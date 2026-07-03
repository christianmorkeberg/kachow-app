<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `user_connections` — mutual links between two users with
 * per-direction sharing scopes (each side controls what THEY share).
 *
 * Security note: connection management is scoped to the acting user (accept/
 * remove/update all verify the user is party to the connection). Actual
 * cross-user data reads happen elsewhere and must consult sharedScopes().
 */
final class Connections
{
    /** Apps that can be shared over a connection. */
    public const APPS = ['workouts', 'wishlist', 'calendar'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Normalizes an arbitrary list of app names to a clean CSV of valid apps. */
    public static function normalizeScopes(array $scopes): string
    {
        $clean = array_map(static fn ($s): string => strtolower(trim((string) $s)), $scopes);
        $valid = array_values(array_unique(array_intersect($clean, self::APPS)));

        return implode(',', $valid);
    }

    /** @return array<int, string> */
    public static function scopesToArray(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn ($s): bool => $s !== ''));
    }

    /**
     * Creates a pending request from requester to addressee. Callers must have
     * already validated (not self, no existing connection). Returns the new id.
     */
    public function request(int $requesterId, int $addresseeId, string $requesterScopes): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_connections (requester_id, addressee_id, requester_scopes, status)
             VALUES (:req, :addr, :scopes, "pending")'
        );
        $stmt->execute([':req' => $requesterId, ':addr' => $addresseeId, ':scopes' => $requesterScopes]);

        return (int) $this->db->lastInsertId();
    }

    /** Returns the connection between two users regardless of direction, or null. */
    public function findBetween(int $a, int $b): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_connections
             WHERE (requester_id = :a1 AND addressee_id = :b1)
                OR (requester_id = :b2 AND addressee_id = :a2)'
        );
        $stmt->execute([':a1' => $a, ':b1' => $b, ':b2' => $b, ':a2' => $a]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_connections WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Accepts a pending request. Only the addressee can accept, and only while
     * pending. Sets the addressee's scopes. Returns true if it changed a row.
     */
    public function accept(int $connectionId, int $addresseeId, string $addresseeScopes): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_connections
             SET status = "accepted", addressee_scopes = :scopes, responded_at = CURRENT_TIMESTAMP
             WHERE id = :id AND addressee_id = :addr AND status = "pending"'
        );
        $stmt->execute([':scopes' => $addresseeScopes, ':id' => $connectionId, ':addr' => $addresseeId]);

        return $stmt->rowCount() > 0;
    }

    /** Deletes a connection (pending or accepted) the user is party to. */
    public function remove(int $userId, int $connectionId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_connections
             WHERE id = :id AND (requester_id = :uid1 OR addressee_id = :uid2)'
        );
        $stmt->execute([':id' => $connectionId, ':uid1' => $userId, ':uid2' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Updates the acting user's own sharing scopes on a connection they belong to.
     */
    public function updateScopes(int $userId, int $connectionId, string $scopes): bool
    {
        $row = $this->findById($connectionId);
        if ($row === null || ((int) $row['requester_id'] !== $userId && (int) $row['addressee_id'] !== $userId)) {
            return false;
        }

        $column = (int) $row['requester_id'] === $userId ? 'requester_scopes' : 'addressee_scopes';
        $stmt = $this->db->prepare("UPDATE user_connections SET {$column} = :scopes WHERE id = :id");
        $stmt->execute([':scopes' => $scopes, ':id' => $connectionId]);

        return true;
    }

    /**
     * All connections involving the user, from their perspective.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                    ru.name AS requester_name, ru.email AS requester_email,
                    au.name AS addressee_name, au.email AS addressee_email
             FROM user_connections c
             JOIN users ru ON ru.id = c.requester_id
             JOIN users au ON au.id = c.addressee_id
             WHERE c.requester_id = :uid1 OR c.addressee_id = :uid2
             ORDER BY c.created_at DESC'
        );
        $stmt->execute([':uid1' => $userId, ':uid2' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $iAmRequester = (int) $row['requester_id'] === $userId;
            $out[] = [
                'connection_id' => (int) $row['id'],
                'status'        => (string) $row['status'],
                'direction'     => $iAmRequester ? 'outgoing' : 'incoming',
                'person'        => [
                    'id'    => $iAmRequester ? (int) $row['addressee_id'] : (int) $row['requester_id'],
                    'name'  => $iAmRequester ? $row['addressee_name'] : $row['requester_name'],
                    'email' => $iAmRequester ? $row['addressee_email'] : $row['requester_email'],
                ],
                'you_share'  => self::scopesToArray($iAmRequester ? $row['requester_scopes'] : $row['addressee_scopes']),
                'they_share' => self::scopesToArray($iAmRequester ? $row['addressee_scopes'] : $row['requester_scopes']),
            ];
        }

        return $out;
    }

    /**
     * Resolves one of the user's connections by the other person's email (exact,
     * case-insensitive) or name (case-insensitive). Email wins over name. Returns
     * the listForUser-style entry, or null.
     */
    public function resolveByOther(int $userId, string $person): ?array
    {
        $needle = strtolower(trim($person));
        if ($needle === '') {
            return null;
        }

        $entries = $this->listForUser($userId);
        foreach ($entries as $entry) {
            if (strtolower((string) $entry['person']['email']) === $needle) {
                return $entry;
            }
        }
        foreach ($entries as $entry) {
            if (strtolower((string) ($entry['person']['name'] ?? '')) === $needle) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The apps $ownerId shares with $viewerId, if they have an accepted
     * connection. Empty array otherwise. This is the gate for cross-user reads.
     *
     * @return array<int, string>
     */
    public function sharedScopes(int $ownerId, int $viewerId): array
    {
        $conn = $this->findBetween($ownerId, $viewerId);
        if ($conn === null || $conn['status'] !== 'accepted') {
            return [];
        }

        $ownerScopes = (int) $conn['requester_id'] === $ownerId
            ? $conn['requester_scopes']
            : $conn['addressee_scopes'];

        return self::scopesToArray($ownerScopes);
    }
}
