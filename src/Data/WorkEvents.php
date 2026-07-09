<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Append-only work-time punches (in/out) and the logic that derives sessions and
 * totals from them. An event log (rather than stored sessions) is deliberate:
 * geofence triggers double-fire and iOS "Leave" is often missed, so we keep raw
 * events and pair them at read time, which survives those glitches.
 *
 * occurred_at is stored in UTC; all day/week boundaries and display use the
 * local zone (Europe/Copenhagen).
 */
final class WorkEvents
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    /** A repeat of the same punch within this many minutes is treated as a bounce. */
    public const DEDUP_MINUTES = 5;

    /** An open session older than this (no clock-out) is a forgotten punch, not "ongoing". */
    private const STALE_OPEN_HOURS = 16;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Records a punch. $occurredAtUtc defaults to now (UTC). De-dupes an identical
     * punch within DEDUP_MINUTES of the previous one.
     *
     * @return array{status:string, id?:int, kind:string, occurred_at:string, local:string}
     */
    public function add(
        int $userId,
        string $kind,
        ?string $occurredAtUtc = null,
        string $source = 'manual',
        ?string $note = null
    ): array {
        $kind = $kind === 'out' ? 'out' : 'in';
        $at   = $occurredAtUtc !== null && $occurredAtUtc !== ''
            ? (new DateTimeImmutable($occurredAtUtc))->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $atStr = $at->format('Y-m-d H:i:s');

        $last = $this->lastEvent($userId);
        if ($last !== null && $last['kind'] === $kind) {
            $lastAt = new DateTimeImmutable($last['occurred_at'], new DateTimeZone('UTC'));
            if (abs($at->getTimestamp() - $lastAt->getTimestamp()) <= self::DEDUP_MINUTES * 60) {
                return [
                    'status'      => 'duplicate',
                    'kind'        => $kind,
                    'occurred_at' => $atStr,
                    'local'       => $this->toLocal($atStr)->format('H:i'),
                ];
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO work_events (user_id, kind, occurred_at, source, note)
             VALUES (:u, :k, :at, :src, :note)'
        );
        $stmt->execute([':u' => $userId, ':k' => $kind, ':at' => $atStr, ':src' => $source, ':note' => $note]);

        return [
            'status'      => 'ok',
            'id'          => (int) $this->db->lastInsertId(),
            'kind'        => $kind,
            'occurred_at' => $atStr,
            'local'       => $this->toLocal($atStr)->format('H:i'),
        ];
    }

    /** Deletes one event by id, scoped to the user. Returns true if removed. */
    public function delete(int $userId, int $eventId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM work_events WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $eventId, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{id:int, kind:string, occurred_at:string}|null */
    public function lastEvent(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, occurred_at FROM work_events WHERE user_id = :u ORDER BY occurred_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'occurred_at' => (string) $r['occurred_at']];
    }

    /**
     * Whether the user is currently clocked in (last event is an 'in' recent enough
     * to be a live session, not a forgotten punch).
     */
    public function isClockedIn(int $userId): bool
    {
        $last = $this->lastEvent($userId);
        if ($last === null || $last['kind'] !== 'in') {
            return false;
        }
        $inAt = new DateTimeImmutable($last['occurred_at'], new DateTimeZone('UTC'));

        return (time() - $inAt->getTimestamp()) <= self::STALE_OPEN_HOURS * 3600;
    }

    /**
     * Summarises worked time over a scope ('today' | 'yesterday' | 'week') or an
     * explicit local date (YYYY-MM-DD). Returns totals, the sessions overlapping
     * the range, any forgotten clock-outs to fix, and a renderable card.
     *
     * @return array{
     *   scope:string, range_label:string, total_minutes:int, total_label:string,
     *   ongoing:bool, sessions:array<int,array<string,mixed>>,
     *   needs_fix:array<int,array<string,mixed>>, card:array<string,mixed>
     * }
     */
    public function summary(int $userId, string $scope = 'today', ?string $date = null): array
    {
        $tz  = new DateTimeZone(self::LOCAL_TZ);
        $utc = new DateTimeZone('UTC');
        [$startLocal, $endLocal, $rangeLabel, $scopeLabel] = $this->rangeFor($scope, $date, $tz);

        $fromUtc = $startLocal->setTimezone($utc);
        $toUtc   = $endLocal->setTimezone($utc);
        // Widen the query back a little to catch an 'in' that started before the range.
        $queryFrom = $fromUtc->modify('-18 hours');

        $events   = $this->eventsBetween($userId, $queryFrom->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s'));
        $sessions = $this->pair($events);

        $nowUtc        = new DateTimeImmutable('now', $utc);
        $totalMinutes  = 0;
        $ongoing       = false;
        $displaySessions = [];
        $needsFix        = [];

        foreach ($sessions as $s) {
            $in  = new DateTimeImmutable($s['in'], $utc);
            $out = $s['out'] !== null ? new DateTimeImmutable($s['out'], $utc) : null;

            $end       = $out;
            $isOngoing = false;
            if ($out === null) {
                if (($nowUtc->getTimestamp() - $in->getTimestamp()) <= self::STALE_OPEN_HOURS * 3600) {
                    $end       = $nowUtc; // live session, still on the clock
                    $isOngoing = true;
                } else {
                    // Forgotten clock-out — surface it, don't count it.
                    if ($in >= $fromUtc && $in < $toUtc) {
                        $needsFix[] = [
                            'in_id' => $s['in_id'],
                            'day'   => $this->toLocal($s['in'])->format('D j M'),
                            'in'    => $this->toLocal($s['in'])->format('H:i'),
                        ];
                    }
                    continue;
                }
            }

            // Overlap of [in, end] with the range → the minutes that count.
            $ovStart = max($in->getTimestamp(), $fromUtc->getTimestamp());
            $ovEnd   = min($end->getTimestamp(), $toUtc->getTimestamp());
            if ($ovEnd > $ovStart) {
                $totalMinutes += (int) round(($ovEnd - $ovStart) / 60);
            }

            // Show sessions that started within the range.
            if ($in >= $fromUtc && $in < $toUtc) {
                $mins = (int) round((($end->getTimestamp()) - $in->getTimestamp()) / 60);
                $displaySessions[] = [
                    'in_id'    => $s['in_id'],
                    'out_id'   => $s['out_id'],
                    'day'      => $this->toLocal($s['in'])->format('D j M'),
                    'in'       => $this->toLocal($s['in'])->format('H:i'),
                    'out'      => $out !== null ? $this->toLocal($s['out'])->format('H:i') : null,
                    'ongoing'  => $isOngoing,
                    'minutes'  => $mins,
                    'duration' => self::fmtDuration($mins),
                ];
                $ongoing = $ongoing || $isOngoing;
            }
        }

        $totalLabel = self::fmtDuration($totalMinutes);
        $card = [
            'kind'     => 'work_hours',
            'title'    => $scopeLabel,
            'range'    => $rangeLabel,
            'total'    => $totalLabel,
            'ongoing'  => $ongoing,
            'sessions' => array_map(static fn (array $s): array => [
                'day'      => $s['day'],
                'in'       => $s['in'],
                'out'      => $s['out'],
                'ongoing'  => $s['ongoing'],
                'duration' => $s['duration'],
            ], $displaySessions),
            'needs_fix' => $needsFix,
        ];

        return [
            'scope'         => $scope,
            'range_label'   => $rangeLabel,
            'total_minutes' => $totalMinutes,
            'total_label'   => $totalLabel,
            'ongoing'       => $ongoing,
            'sessions'      => $displaySessions,
            'needs_fix'     => $needsFix,
            'card'          => $card,
        ];
    }

    /**
     * Pairs a time-ordered event list into sessions. Rule: the first 'in' opens a
     * session; a following 'out' closes it; extra 'in's while open and extra 'out's
     * while closed are ignored (dedup/misfire tolerance). A trailing open 'in' is
     * returned with out=null.
     *
     * @param array<int,array{id:int,kind:string,occurred_at:string}> $events ordered asc
     * @return array<int,array{in:string, out:?string, in_id:int, out_id:?int}>
     */
    private function pair(array $events): array
    {
        $sessions = [];
        $openIn   = null;
        foreach ($events as $e) {
            if ($e['kind'] === 'in') {
                if ($openIn === null) {
                    $openIn = $e;
                }
                // else: already open → ignore duplicate/re-entry
            } else { // out
                if ($openIn !== null) {
                    $sessions[] = [
                        'in'     => $openIn['occurred_at'],
                        'out'    => $e['occurred_at'],
                        'in_id'  => $openIn['id'],
                        'out_id' => $e['id'],
                    ];
                    $openIn = null;
                }
                // else: stray out → ignore
            }
        }
        if ($openIn !== null) {
            $sessions[] = ['in' => $openIn['occurred_at'], 'out' => null, 'in_id' => $openIn['id'], 'out_id' => null];
        }

        return $sessions;
    }

    /**
     * @return array<int,array{id:int, kind:string, occurred_at:string}>
     */
    private function eventsBetween(int $userId, string $fromUtc, string $toUtc): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, occurred_at FROM work_events
             WHERE user_id = :u AND occurred_at >= :from AND occurred_at < :to
             ORDER BY occurred_at ASC, id ASC'
        );
        $stmt->execute([':u' => $userId, ':from' => $fromUtc, ':to' => $toUtc]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'occurred_at' => (string) $r['occurred_at']];
        }

        return $out;
    }

    /**
     * @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string,3:string}
     *         [startLocal, endLocal, rangeLabel, scopeLabel]
     */
    private function rangeFor(string $scope, ?string $date, DateTimeZone $tz): array
    {
        $now = new DateTimeImmutable('now', $tz);

        if ($date !== null && $date !== '') {
            $d = (new DateTimeImmutable($date, $tz))->setTime(0, 0);
            return [$d, $d->modify('+1 day'), $d->format('D j M'), $d->format('D j M')];
        }

        if ($scope === 'week') {
            $dow   = (int) $now->format('N'); // 1 = Mon
            $start = $now->setTime(0, 0)->modify('-' . ($dow - 1) . ' days');
            $end   = $start->modify('+7 days');
            return [$start, $end, $start->format('j M') . ' – ' . $end->modify('-1 day')->format('j M'), 'This week'];
        }

        if ($scope === 'yesterday') {
            $start = $now->setTime(0, 0)->modify('-1 day');
            return [$start, $start->modify('+1 day'), $start->format('D j M'), 'Yesterday'];
        }

        $start = $now->setTime(0, 0); // today
        return [$start, $start->modify('+1 day'), $start->format('D j M'), 'Today'];
    }

    private function toLocal(string $utcDateTime): DateTimeImmutable
    {
        return (new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::LOCAL_TZ));
    }

    private static function fmtDuration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m . 'm';
        }

        return $m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm';
    }
}
