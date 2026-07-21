<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * One-off user reminders. remind_at is stored in UTC; a 5-minute cron claims due ones
 * (atomically, so a reminder fires exactly once) and pushes them.
 */
final class Reminders
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Creates a pending reminder ($remindAtUtc as 'Y-m-d H:i:s' UTC). Returns its id. */
    public function create(int $userId, string $remindAtUtc, string $text): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO reminders (user_id, remind_at, text) VALUES (:u, :at, :t)'
        );
        $stmt->execute([':u' => $userId, ':at' => $remindAtUtc, ':t' => mb_substr(trim($text), 0, 500)]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Pending reminders whose time has arrived (UTC), oldest first.
     *
     * @return array<int, array{id:int, user_id:int, text:string, remind_at:string}>
     */
    public function due(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt  = $this->db->query(
            "SELECT id, user_id, text, remind_at FROM reminders
             WHERE status = 'pending' AND remind_at <= UTC_TIMESTAMP()
             ORDER BY remind_at ASC, id ASC
             LIMIT " . $limit
        );

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'        => (int) $r['id'],
                'user_id'   => (int) $r['user_id'],
                'text'      => (string) $r['text'],
                'remind_at' => (string) $r['remind_at'],
            ];
        }

        return $out;
    }

    /**
     * Atomically claims a reminder for delivery. Returns true only if THIS call flips
     * it from pending → sent (so overlapping cron runs can't double-send).
     */
    public function claim(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET status = 'sent', sent_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * A user's pending (future) reminders, soonest first.
     *
     * @return array<int, array{id:int, text:string, remind_at:string}>
     */
    public function upcoming(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt  = $this->db->prepare(
            "SELECT id, text, remind_at FROM reminders
             WHERE user_id = :u AND status = 'pending'
             ORDER BY remind_at ASC, id ASC
             LIMIT " . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['id' => (int) $r['id'], 'text' => (string) $r['text'], 'remind_at' => (string) $r['remind_at']];
        }

        return $out;
    }

    /** Cancels a user's pending reminder. Returns true if one was cancelled. */
    public function cancel(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET status = 'cancelled'
             WHERE id = :id AND user_id = :u AND status = 'pending'"
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{id:int, text:string, remind_at:string, status:string}|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, text, remind_at, status FROM reminders WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'id'        => (int) $r['id'],
            'text'      => (string) $r['text'],
            'remind_at' => (string) $r['remind_at'],
            'status'    => (string) $r['status'],
        ];
    }
}
