<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * One-off user reminders. Two kinds share the table:
 *  - timed: remind_at (UTC); a 5-minute cron claims due ones (atomically, so a reminder fires
 *    exactly once) and pushes them;
 *  - place: remind_at NULL, place_id or place_type set; fired when location tracking sees the
 *    user arrive there (Notify\PlaceReminderRunner, from api/owntracks.php).
 *
 * A reminder that has fired stays OPEN (shown on the Reminders card, counted by the app badge) until the user approves it.
 * The user can ask for a nudge: it is then scheduled again (status back to 'pending') but is still open. One place
 * (this class) owns those rules; the card, the endpoint, the push badge and the assistant tools all call it.
 */
final class Reminders
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

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
             WHERE user_id = :u AND status = 'pending' AND sent_at IS NULL
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

    /** Cancels a user's pending or still-open reminder. Returns true if one was cancelled. */
    public function cancel(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET status = 'cancelled'
             WHERE id = :id AND user_id = :u AND status IN ('pending', 'sent') AND approved_at IS NULL"
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Interprets a local (Europe/Copenhagen) wall-clock time like "2026-07-22 09:00", tolerating a stray "Z"/offset the
     * model added. Null if it can't be read. Shared by set_reminder and resolve_reminder.
     */
    public static function parseLocal(string $at): ?DateTimeImmutable
    {
        $s = preg_replace('/\s*(Z|[+-]\d{2}:?\d{2})\s*$/i', '', trim($at)) ?? trim($at);
        try {
            return new DateTimeImmutable($s, new DateTimeZone(self::LOCAL_TZ));
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Fired and not yet approved or cancelled — a nudge that is scheduled counts, it is still unticked. */
    private const OPEN = "approved_at IS NULL AND sent_at IS NOT NULL AND status IN ('sent', 'pending')";

    /**
     * The reminders waiting for the user's approval, oldest first. `nudge` is when the next nudge fires (UTC) when the
     * user asked for one, else null.
     *
     * @return list<array{id:int, text:string, fired:string, nudge:?string, place:?string}>
     */
    public function openFor(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt  = $this->db->prepare(
            'SELECT id, text, status, remind_at, sent_at, place_label FROM reminders
             WHERE user_id = :u AND ' . self::OPEN . '
             ORDER BY sent_at ASC, id ASC
             LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'    => (int) $r['id'],
                'text'  => (string) $r['text'],
                'fired' => (string) $r['sent_at'],
                'nudge' => $r['status'] === 'pending' && $r['remind_at'] !== null ? (string) $r['remind_at'] : null,
                'place' => ($r['place_label'] ?? '') !== '' ? (string) $r['place_label'] : null,
            ];
        }

        return $out;
    }

    /** How many reminders await approval — the number on the app icon. */
    public function openCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM reminders WHERE user_id = :u AND ' . self::OPEN);
        $stmt->execute([':u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** The user approves (ticks) an open reminder. A scheduled nudge is dropped with it. True if one was approved. */
    public function approve(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET approved_at = UTC_TIMESTAMP(), status = 'sent'
             WHERE id = :id AND user_id = :u AND " . self::OPEN
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** The user asks to be nudged again at $atUtc ('Y-m-d H:i:s'). The reminder stays open. True if it was open. */
    public function nudge(int $userId, int $id, string $atUtc): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE reminders SET status = 'pending', remind_at = :at
             WHERE id = :id AND user_id = :u AND " . self::OPEN
        );
        $stmt->execute([':at' => $atUtc, ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** UTC 'Y-m-d H:i:s' from a local time or, when $at is empty, from now + $minutes. Null if unreadable or not in the future. */
    public static function futureUtc(string $at, int $minutes = 0): ?string
    {
        $utc = new DateTimeZone('UTC');
        if (trim($at) !== '') {
            $local = self::parseLocal($at);
            $when  = $local?->setTimezone($utc);
        } else {
            $when = $minutes > 0 ? (new DateTimeImmutable('now', $utc))->modify('+' . $minutes . ' minutes') : null;
        }
        if ($when === null || $when->getTimestamp() <= time()) {
            return null;
        }

        return $when->format('Y-m-d H:i:s');
    }

    /** "Mon 2 Nov, 08:00" in the user's local time, from a UTC 'Y-m-d H:i:s'. */
    public static function localLabel(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::LOCAL_TZ))->format('D j M, H:i');
    }

    /**
     * The Reminders card: what awaits approval (emphasized, with a tick) and what is still to come. `focus` is the
     * reminder a tapped push was about. Used by the tool, the push deep link and api/reminders.php.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, ?int $focus = null): array
    {
        $open = array_map(fn (array $r): array => [
            'id'    => $r['id'],
            'text'  => $r['text'],
            'fired' => self::localLabel($r['fired']),
            'nudge' => $r['nudge'] !== null ? self::localLabel($r['nudge']) : null,
            'place' => $r['place'],
        ], $this->openFor($userId));

        $upcoming = array_map(fn (array $r): array => [
            'id'    => $r['id'],
            'text'  => $r['text'],
            'when'  => $r['remind_at'] !== null ? self::localLabel($r['remind_at']) : null,
            'place' => $r['place'],
        ], $this->upcoming($userId));

        return ['kind' => 'reminders', 'title' => 'Reminders', 'focus' => $focus, 'open' => $open, 'upcoming' => $upcoming, 'badge' => count($open)];
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
