<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Per-user bearer tokens for URL-triggered automations that run without a login
 * session (e.g. an iOS Shortcut fetching a URL when you arrive at work). A token
 * maps to a user_id server-side, so the caller never supplies a user id directly
 * — consistent with the rule that userId is never client/model-supplied.
 *
 * One token per (user, scope). Rotatable if a URL ever leaks.
 */
final class ApiTokens
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Returns the user's token for a scope, creating one on first use. */
    public function ensure(int $userId, string $scope): string
    {
        $stmt = $this->db->prepare('SELECT token FROM api_tokens WHERE user_id = :u AND scope = :s LIMIT 1');
        $stmt->execute([':u' => $userId, ':s' => $scope]);
        $token = $stmt->fetchColumn();
        if ($token !== false) {
            return (string) $token;
        }

        return $this->create($userId, $scope);
    }

    /** Replaces the user's token for a scope with a fresh one. */
    public function rotate(int $userId, string $scope): string
    {
        $this->db->prepare('DELETE FROM api_tokens WHERE user_id = :u AND scope = :s')
            ->execute([':u' => $userId, ':s' => $scope]);

        return $this->create($userId, $scope);
    }

    /**
     * Resolves a token (within a scope) to its user id, or null if unknown.
     * Touches last_used_at so a leaked/stale token is visible.
     */
    public function userForToken(string $token, string $scope): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $stmt = $this->db->prepare('SELECT user_id FROM api_tokens WHERE token = :t AND scope = :s LIMIT 1');
        $stmt->execute([':t' => $token, ':s' => $scope]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        $this->db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE token = :t')->execute([':t' => $token]);

        return (int) $id;
    }

    /**
     * Seconds since the user's token for a scope was last used (null = never used / no token).
     * For a device that polls continuously this doubles as a heartbeat. Computed in SQL so it
     * compares the DB's own clock with the DB's own timestamp (no PHP timezone involved).
     */
    public function secondsSinceUse(int $userId, string $scope): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, last_used_at, NOW()) FROM api_tokens
             WHERE user_id = :u AND scope = :s AND last_used_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':s' => $scope]);
        $secs = $stmt->fetchColumn();

        return $secs === false || $secs === null ? null : max(0, (int) $secs);
    }

    /** The raw last-used timestamp (server time) of a token, or null if it was never used — identifies one "outage" for de-duplication. */
    public function lastUsedAt(int $userId, string $scope): ?string
    {
        $stmt = $this->db->prepare('SELECT last_used_at FROM api_tokens WHERE user_id = :u AND scope = :s AND last_used_at IS NOT NULL LIMIT 1');
        $stmt->execute([':u' => $userId, ':s' => $scope]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }

    /** Whether the user has a token for this scope (without creating one, unlike ensure()). */
    public function has(int $userId, string $scope): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM api_tokens WHERE user_id = :u AND scope = :s LIMIT 1');
        $stmt->execute([':u' => $userId, ':s' => $scope]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Every user that has a token for a scope (e.g. everyone with a home agent).
     *
     * @return list<int>
     */
    public function userIdsWithScope(string $scope): array
    {
        $stmt = $this->db->prepare('SELECT user_id FROM api_tokens WHERE scope = :s');
        $stmt->execute([':s' => $scope]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function create(int $userId, string $scope): string
    {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $this->db->prepare('INSERT INTO api_tokens (user_id, scope, token) VALUES (:u, :s, :t)')
            ->execute([':u' => $userId, ':s' => $scope, ':t' => $token]);

        return $token;
    }
}
