<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Tools\ToolRegistry;
use PDO;
use Throwable;

/**
 * "Do you want me to …?" from an unattended scheduled task, with the answer built in. The run attaches the exact tool calls to make on a yes
 * (notify_me's `offer`); the push opens a Run / Skip sheet and Run executes the stored calls — no chat turn, no model, so it cannot misunderstand
 * "run it". Only house tools are accepted (a stored offer must never be able to send mail or touch the books).
 */
final class TaskOffers
{
    /** What an offer may do: lights, radiators. */
    public const TOOLS = ['home_device_call', 'hue_color', 'set_thermostat'];

    public const MAX_CALLS = 40;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Checks and normalises what the model proposed.
     *
     * @param mixed $calls
     * @return array{calls?:list<array{tool:string, args:array<string,mixed>}>, error?:string}
     */
    public static function clean(mixed $calls): array
    {
        if (!is_array($calls) || $calls === [] || !array_is_list($calls)) {
            return ['error' => 'offer.calls must be a non-empty list of {tool, args}.'];
        }
        if (count($calls) > self::MAX_CALLS) {
            return ['error' => 'An offer may have at most ' . self::MAX_CALLS . ' calls.'];
        }
        $out = [];
        foreach ($calls as $c) {
            $tool = is_array($c) ? (string) ($c['tool'] ?? '') : '';
            if (!in_array($tool, self::TOOLS, true)) {
                return ['error' => 'An offer can only use these tools: ' . implode(', ', self::TOOLS) . '.'];
            }
            $args  = is_array($c['args'] ?? null) ? $c['args'] : [];
            $out[] = ['tool' => $tool, 'args' => $args];
        }

        return ['calls' => $out];
    }

    /** A new pending offer; an older pending one of the same task is superseded (only the latest question matters). */
    public function create(int $userId, ?int $conversationId, ?int $taskId, string $question, array $calls, ?int $now = null): int
    {
        $at = gmdate('Y-m-d H:i:s', $now ?? time());
        if ($taskId !== null) {
            $this->db->prepare("UPDATE task_offers SET status = 'superseded', answered_at = :at WHERE user_id = :u AND task_id = :t AND status = 'pending'")
                ->execute([':at' => $at, ':u' => $userId, ':t' => $taskId]);
        }
        $this->db->prepare(
            'INSERT INTO task_offers (user_id, conversation_id, task_id, question, calls, status, created_at) VALUES (:u, :c, :t, :q, :calls, :s, :at)'
        )->execute([
            ':u' => $userId, ':c' => $conversationId, ':t' => $taskId, ':q' => mb_substr($question, 0, 300),
            ':calls' => (string) json_encode($calls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':s' => 'pending', ':at' => $at,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array{id:int, conversation_id:?int, task_id:?int, question:string, calls:list<array<string,mixed>>, status:string, result:?string}|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, conversation_id, task_id, question, calls, status, result FROM task_offers WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if (!is_array($r)) {
            return null;
        }
        $calls = json_decode((string) $r['calls'], true);

        return [
            'id' => (int) $r['id'], 'conversation_id' => $r['conversation_id'] === null ? null : (int) $r['conversation_id'],
            'task_id' => $r['task_id'] === null ? null : (int) $r['task_id'], 'question' => (string) $r['question'],
            'calls' => is_array($calls) ? $calls : [], 'status' => (string) $r['status'], 'result' => $r['result'] === null ? null : (string) $r['result'],
        ];
    }

    /** pending → running, once: false when it was already answered (a double tap, or a second device). */
    public function claim(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE task_offers SET status = 'running' WHERE id = :id AND user_id = :u AND status = 'pending'");
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() === 1;
    }

    public function skip(int $userId, int $id, ?int $now = null): bool
    {
        $stmt = $this->db->prepare("UPDATE task_offers SET status = 'skipped', answered_at = :at WHERE id = :id AND user_id = :u AND status = 'pending'");
        $stmt->execute([':at' => gmdate('Y-m-d H:i:s', $now ?? time()), ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() === 1;
    }

    public function finish(int $userId, int $id, string $status, string $result, ?int $now = null): void
    {
        $this->db->prepare('UPDATE task_offers SET status = :s, result = :r, answered_at = :at WHERE id = :id AND user_id = :u')
            ->execute([':s' => $status, ':r' => mb_substr($result, 0, 2000), ':at' => gmdate('Y-m-d H:i:s', $now ?? time()), ':id' => $id, ':u' => $userId]);
    }

    /**
     * Makes the stored calls, all of them (one failing does not stop the rest), and says how it went.
     *
     * @param list<array<string,mixed>> $calls
     * @return array{ok:bool, done:int, failed:int, text:string}
     */
    public static function run(ToolRegistry $registry, int $userId, array $calls): array
    {
        $done = 0;
        $errors = [];
        foreach (array_slice($calls, 0, self::MAX_CALLS) as $c) {
            $tool = (string) ($c['tool'] ?? '');
            if (!in_array($tool, self::TOOLS, true)) {
                $errors[] = $tool . ': not allowed';
                continue;
            }
            try {
                $res = $registry->dispatch($tool, is_array($c['args'] ?? null) ? $c['args'] : [], $userId);
            } catch (Throwable $e) {
                $res = ['error' => $e->getMessage()];
            }
            if (isset($res['error'])) {
                $errors[] = $tool . ': ' . (is_string($res['error']) ? $res['error'] : (string) json_encode($res['error']));
            } else {
                $done++;
            }
        }
        $failed = count($errors);
        $total  = $done + $failed;
        $text   = $failed === 0
            ? 'Done — ' . $done . ' of ' . $total . ' steps went through.'
            : $done . ' of ' . $total . ' steps went through. ' . implode('; ', array_slice($errors, 0, 3));

        return ['ok' => $failed === 0, 'done' => $done, 'failed' => $failed, 'text' => $text];
    }
}
