<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * One-off user reminders. Two kinds share the table:
 *  - timed: remind_at (UTC); a 5-minute cron claims due ones (atomically, so a reminder fires
 *    exactly once) and pushes them;
 *  - place: remind_at NULL, place_id or place_type set; fired when location tracking sees the
 *    user arrive there (Notify\PlaceReminderRunner, from api/owntracks.php).
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
     * Creates a pending reminder that fires on arrival at a saved place ($placeId) or at any
     * place of a type ($placeType). $armed = false when the user is there right now, so it
     * waits for them to leave and come back. Returns its id.
     */
    public function createAtPlace(int $userId, ?int $placeId, ?string $placeType, string $label, string $text, bool $armed = true): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO reminders (user_id, remind_at, place_id, place_type, place_label, place_armed, text)
             VALUES (:u, NULL, :pid, :ptype, :label, :armed, :t)'
        );
        $stmt->execute([
            ':u'     => $userId,
            ':pid'   => $placeId,
            ':ptype' => $placeType,
            ':label' => mb_substr(trim($label), 0, 80),
            ':armed' => $armed ? 1 : 0,
            ':t'     => mb_substr(trim($text), 0, 500),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Pending timed reminders whose time has arrived (UTC), oldest first. Place reminders have
     * no time and never show up here.
     *
     * @return array<int, array{id:int, user_id:int, text:string, remind_at:string}>
     */
    public function due(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt  = $this->db->query(
            "SELECT id, user_id, text, remind_at FROM reminders
             WHERE status = 'pending' AND remind_at IS NOT NULL AND remind_at <= UTC_TIMESTAMP()
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
     * A user's pending place reminders (for the arrival check).
     *
     * @return list<array{id:int, text:string, place_id:?int, place_type:?string, place_label:string, armed:bool, created_ts:int}>
     */
    public function pendingAtPlaces(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, text, place_id, place_type, place_label, place_armed, UNIX_TIMESTAMP(created_at) AS created_ts FROM reminders
             WHERE user_id = :u AND status = 'pending' AND remind_at IS NULL
             ORDER BY id ASC"
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'text'        => (string) $r['text'],
                'place_id'    => $r['place_id'] !== null ? (int) $r['place_id'] : null,
                'place_type'  => $r['place_type'] !== null ? (string) $r['place_type'] : null,
                'place_label' => (string) ($r['place_label'] ?? ''),
                'armed'       => (bool) $r['place_armed'],
                // Epoch via the DB (a TIMESTAMP reads back in the session time zone, not UTC).
                'created_ts'  => (int) $r['created_ts'],
            ];
        }

        return $out;
    }

    /** The user has left the place: from now on, arriving there fires the reminder. */
    public function arm(int $id): void
    {
        $this->db->prepare('UPDATE reminders SET place_armed = 1 WHERE id = :id')->execute([':id' => $id]);
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
     * A user's pending reminders: timed ones soonest first, then place ones. `remind_at` is
     * null for a place reminder, which carries `place` (what the user called it) instead.
     *
     * @return array<int, array{id:int, text:string, remind_at:?string, place:?string}>
     */
    public function upcoming(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt  = $this->db->prepare(
            "SELECT id, text, remind_at, place_label FROM reminders
             WHERE user_id = :u AND status = 'pending'
             ORDER BY remind_at IS NULL, remind_at ASC, id ASC
             LIMIT " . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'        => (int) $r['id'],
                'text'      => (string) $r['text'],
                'remind_at' => $r['remind_at'] !== null ? (string) $r['remind_at'] : null,
                'place'     => $r['remind_at'] === null ? (string) ($r['place_label'] ?? '') : null,
            ];
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

    /** @return array{id:int, text:string, remind_at:?string, place:?string, status:string}|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, text, remind_at, place_label, status FROM reminders WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'id'        => (int) $r['id'],
            'text'      => (string) $r['text'],
            'remind_at' => $r['remind_at'] !== null ? (string) $r['remind_at'] : null,
            'place'     => $r['remind_at'] === null ? (string) ($r['place_label'] ?? '') : null,
            'status'    => (string) $r['status'],
        ];
    }
}
