<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Stores a user's device push subscriptions (the endpoint + keys the browser
 * hands us). Dead ones (the push service reports 404/410) are pruned on send.
 */
final class PushSubscriptions
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Upserts a subscription (idempotent by endpoint). Returns the row id. */
    public function save(int $userId, string $endpoint, string $p256dh, string $auth, ?string $ua = null): int
    {
        $hash = hash('sha256', $endpoint);
        $stmt = $this->db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, ua)
             VALUES (:u, :e, :h, :p, :a, :ua)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                                     auth = VALUES(auth), ua = VALUES(ua)'
        );
        $stmt->execute([':u' => $userId, ':e' => $endpoint, ':h' => $hash, ':p' => $p256dh, ':a' => $auth, ':ua' => $ua]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<int, array{id:int, endpoint:string, p256dh:string, auth:string}>
     */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'endpoint' => (string) $r['endpoint'],
                'p256dh'   => (string) $r['p256dh'],
                'auth'     => (string) $r['auth'],
            ];
        }

        return $out;
    }

    public function hasAny(int $userId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM push_subscriptions WHERE user_id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int, int> distinct user ids that have at least one subscription */
    public function subscribedUserIds(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT user_id FROM push_subscriptions');

        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = :h')
            ->execute([':h' => hash('sha256', $endpoint)]);
    }

    public function touch(int $id): void
    {
        $this->db->prepare('UPDATE push_subscriptions SET last_sent_at = NOW() WHERE id = :id')->execute([':id' => $id]);
    }
}
