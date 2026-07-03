<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `invites` — single-use registration invitations created
 * by an admin. Only a hash of the invite token is stored; the raw token lives
 * only in the registration link the admin shares.
 */
final class Invites
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Creates an invite for an email, storing the token hash and an absolute UTC
     * expiry ('Y-m-d H:i:s'). Returns the new invite id.
     */
    public function create(string $email, string $tokenHash, int $invitedBy, string $expiresAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO invites (email, token_hash, invited_by, expires_at)
             VALUES (:email, :token_hash, :invited_by, :expires_at)'
        );
        $stmt->execute([
            ':email'      => $email,
            ':token_hash' => $tokenHash,
            ':invited_by' => $invitedBy,
            ':expires_at' => $expiresAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the matching invite if it is unused and unexpired, else null.
     * Expiry is compared against a caller-supplied UTC 'now'.
     */
    public function findValid(string $tokenHash, ?string $nowUtc = null): ?array
    {
        $nowUtc ??= gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'SELECT id, email, token_hash, invited_by, expires_at, used_at, created_at
             FROM invites
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now'
        );
        $stmt->execute([':token_hash' => $tokenHash, ':now' => $nowUtc]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Marks an invite as consumed (call right after the account is created).
     */
    public function markUsed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE invites SET used_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
