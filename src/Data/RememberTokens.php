<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for the `remember_tokens` table.
 *
 * Only ever stores a HASH of a remember-me token, never the raw token (the raw
 * value lives solely in the user's cookie). Auth/RememberMe.php owns the token
 * lifecycle and calls into here — no SQL lives in Auth/.
 */
final class RememberTokens
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Stores a token hash for a user with an absolute UTC expiry
     * ('Y-m-d H:i:s'). Returns the new row id.
     */
    public function create(int $userId, string $tokenHash, string $expiresAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':token_hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the matching, non-expired token row, or null.
     *
     * Expiry is compared against a caller-supplied UTC 'now' rather than MySQL
     * NOW(), so it stays correct regardless of the DB server's timezone.
     */
    public function findValid(string $tokenHash, ?string $nowUtc = null): ?array
    {
        $nowUtc ??= gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'SELECT id, user_id, token_hash, expires_at, created_at
             FROM remember_tokens
             WHERE token_hash = :token_hash AND expires_at > :now'
        );
        $stmt->execute([
            ':token_hash' => $tokenHash,
            ':now'        => $nowUtc,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Deletes a single token by its hash (used on rotation and on logout).
     */
    public function deleteByHash(string $tokenHash): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens WHERE token_hash = :token_hash'
        );
        $stmt->execute([':token_hash' => $tokenHash]);
    }

    /**
     * Deletes every token for a user (e.g. "log out everywhere").
     */
    public function deleteAllForUser(int $userId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
    }

    /**
     * Housekeeping: removes expired tokens. Returns the number deleted.
     * Intended to be run periodically (cron).
     */
    public function deleteExpired(?string $nowUtc = null): int
    {
        $nowUtc ??= gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'DELETE FROM remember_tokens WHERE expires_at <= :now'
        );
        $stmt->execute([':now' => $nowUtc]);

        return $stmt->rowCount();
    }
}
