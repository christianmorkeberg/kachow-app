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

    private function create(int $userId, string $scope): string
    {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $this->db->prepare('INSERT INTO api_tokens (user_id, scope, token) VALUES (:u, :s, :t)')
            ->execute([':u' => $userId, ':s' => $scope, ':t' => $token]);

        return $token;
    }
}
