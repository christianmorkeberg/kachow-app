<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * The command queue between the assistant and the home agent (a Pico W on the home network).
 *
 * The assistant enqueues {device, method, path, body}; the agent long-polls api/agent.php,
 * claims one, runs it against the device it knows by that alias (the alias -> address map and
 * the allowed paths live ON THE PICO, never here, so the server cannot aim it at an arbitrary
 * host), and posts the result back. enqueue() only checks the request is well-formed.
 *
 * No time arithmetic in SQL on purpose: a waiter that gives up marks its own command
 * 'expired', so a command can never be picked up late by an agent that was offline.
 */
final class AgentCommands
{
    public const SCOPE = 'agent';

    private const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * @param array<string, mixed>|null $body JSON body for POST/PUT
     * @throws InvalidArgumentException on a malformed device, method or path
     */
    public function enqueue(int $userId, string $device, string $method, string $path, ?array $body = null, ?int $ttlSeconds = null): int
    {
        $device = strtolower(trim($device));
        $method = strtoupper(trim($method));
        $path   = trim($path);

        if (preg_match('/^[a-z0-9_-]{1,32}$/', $device) !== 1) {
            throw new InvalidArgumentException('Device must be a short name like "hue" (letters, digits, - and _).');
        }
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException('Method must be one of ' . implode(', ', self::METHODS) . '.');
        }
        if ($path === '' || $path[0] !== '/' || strlen($path) > 255
            || str_contains($path, '..') || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw new InvalidArgumentException('Path must start with "/" and contain no spaces or "..".');
        }
        $json = null;
        if ($body !== null && $body !== []) {
            $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($json) > 4000) {
                throw new InvalidArgumentException('Body too large (max 4000 bytes).');
            }
        }

        // A command nobody waits for (ttl) must not run late: it carries its own expiry, which claimNext() honours. Without a ttl the old rule holds —
        // the waiter expires its command when it gives up.
        // The ordinary insert never touches the expires_at column, so the queue keeps working on a server where the migration has not run yet.
        if ($ttlSeconds === null) {
            $this->db->prepare(
                'INSERT INTO home_agent_commands (user_id, device, method, path, body) VALUES (:u, :d, :m, :p, :b)'
            )->execute([':u' => $userId, ':d' => $device, ':m' => $method, ':p' => $path, ':b' => $json]);

            return (int) $this->db->lastInsertId();
        }
        $this->db->prepare(
            'INSERT INTO home_agent_commands (user_id, device, method, path, body, expires_at) VALUES (:u, :d, :m, :p, :b, :x)'
        )->execute([':u' => $userId, ':d' => $device, ':m' => $method, ':p' => $path, ':b' => $json, ':x' => gmdate('Y-m-d H:i:s', time() + max(1, $ttlSeconds))]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Atomically hands the oldest pending command to the agent (pending -> sent), or null.
     * Two agents polling at once cannot both get the same command: the UPDATE only wins
     * for one of them.
     *
     * @return array{id:int, device:string, method:string, path:string, body:?array}|null
     */
    public function claimNext(int $userId): ?array
    {
        // Commands whose own expiry has passed (fired without a waiter, then the Pico was away) are dropped, never run late.
        try {
            $this->db->prepare("UPDATE home_agent_commands SET status = 'expired' WHERE user_id = :u AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < :now")
                ->execute([':u' => $userId, ':now' => gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            // the expires_at column does not exist yet (migration 2026-10-30 not run): nothing can carry an expiry, so nothing to drop
        }
        for ($try = 0; $try < 3; $try++) {
            $stmt = $this->db->prepare(
                "SELECT id, device, method, path, body FROM home_agent_commands
                 WHERE user_id = :u AND status = 'pending' ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute([':u' => $userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }

            $claim = $this->db->prepare(
                "UPDATE home_agent_commands SET status = 'sent', sent_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = 'pending'"
            );
            $claim->execute([':id' => $row['id']]);
            if ($claim->rowCount() > 0) {
                $body = $row['body'] !== null ? json_decode((string) $row['body'], true) : null;

                return [
                    'id'     => (int) $row['id'],
                    'device' => (string) $row['device'],
                    'method' => (string) $row['method'],
                    'path'   => (string) $row['path'],
                    'body'   => is_array($body) ? $body : null,
                ];
            }
        }

        return null;
    }

    /** Records the agent's answer. Only a command that was actually handed out ('sent') accepts one. */
    public function complete(int $userId, int $id, ?int $httpStatus, ?string $response, ?string $error): bool
    {
        $failed = $error !== null && $error !== '';
        $stmt   = $this->db->prepare(
            "UPDATE home_agent_commands
             SET status = :s, http_status = :h, response = :r, error = :e, done_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :u AND status = 'sent'"
        );
        $stmt->execute([
            ':s'  => $failed ? 'failed' : 'done',
            ':h'  => $httpStatus,
            ':r'  => $response !== null ? mb_substr($response, 0, 60000) : null,
            ':e'  => $failed ? mb_substr((string) $error, 0, 255) : null,
            ':id' => $id,
            ':u'  => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, device, method, path, status, http_status, response, error
             FROM home_agent_commands WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Waits for the agent to answer a command it was given. Polls the row every 100 ms; on
     * timeout the command is marked 'expired' (if nobody has answered) so it can't run late.
     *
     * @return array{state:string, http_status:?int, response:?string, error:?string}
     *         state: done | failed | timeout
     */
    public function awaitResult(int $userId, int $id, float $timeoutSeconds, bool $expireOnTimeout = true): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $row = $this->get($userId, $id);
            if ($row !== null && in_array($row['status'], ['done', 'failed'], true)) {
                return [
                    'state'       => (string) $row['status'],
                    'http_status' => $row['http_status'] !== null ? (int) $row['http_status'] : null,
                    'response'    => $row['response'] !== null ? (string) $row['response'] : null,
                    'error'       => $row['error'] !== null ? (string) $row['error'] : null,
                ];
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        // $expireOnTimeout = false: the command was enqueued with a ttl and stays queued (a soft wait, see HomeDeviceCall::executeSoft).
        if ($expireOnTimeout) {
            $this->db->prepare(
                "UPDATE home_agent_commands SET status = 'expired' WHERE id = :id AND user_id = :u AND status IN ('pending', 'sent')"
            )->execute([':id' => $id, ':u' => $userId]);
        }

        return ['state' => 'timeout', 'http_status' => null, 'response' => null, 'error' => null];
    }
}
