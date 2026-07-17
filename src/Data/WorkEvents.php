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

    /** Selectable bucketings for the work-hours bar chart. */
    public const CHART_MODES = ['week', '4w', '12w', 'year'];

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
        ?string $note = null,
        ?string $location = null
    ): array {
        $kind = $kind === 'out' ? 'out' : 'in';
        $loc  = $location !== null ? mb_substr(trim($location), 0, 64) : null;
        if ($loc === '') {
            $loc = null;
        }
        $at   = $occurredAtUtc !== null && $occurredAtUtc !== ''
            ? (new DateTimeImmutable($occurredAtUtc))->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $atStr = $at->format('Y-m-d H:i:s');

        // A bounce is the same punch (same kind AND same place) within the window;
        // a quick out-here / in-there across two workplaces is NOT a duplicate.
        $last = $this->lastEvent($userId);
        if ($last !== null && $last['kind'] === $kind
            && $this->locKey($last['location']) === $this->locKey($loc)) {
            $lastAt = new DateTimeImmutable($last['occurred_at'], new DateTimeZone('UTC'));
            if (abs($at->getTimestamp() - $lastAt->getTimestamp()) <= self::DEDUP_MINUTES * 60) {
                return [
                    'status'      => 'duplicate',
                    'kind'        => $kind,
                    'location'    => $loc,
                    'occurred_at' => $atStr,
                    'local'       => $this->toLocal($atStr)->format('H:i'),
                ];
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO work_events (user_id, kind, occurred_at, location, source, note)
             VALUES (:u, :k, :at, :loc, :src, :note)'
        );
        $stmt->execute([':u' => $userId, ':k' => $kind, ':at' => $atStr, ':loc' => $loc, ':src' => $source, ':note' => $note]);

        return [
            'status'      => 'ok',
            'id'          => (int) $this->db->lastInsertId(),
            'kind'        => $kind,
            'location'    => $loc,
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

    /** @return array{id:int, kind:string, occurred_at:string, location:?string}|null */
    public function lastEvent(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, occurred_at, location FROM work_events WHERE user_id = :u ORDER BY occurred_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'id'          => (int) $r['id'],
            'kind'        => (string) $r['kind'],
            'occurred_at' => (string) $r['occurred_at'],
            'location'    => $r['location'] !== null ? (string) $r['location'] : null,
        ];
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
     * The user's currently-open sessions (latest event per workplace is an 'in').
     *
     * @return array<int, array{place:?string, in_id:int, occurred_at:string}>
     */
    public function openSessions(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, kind, location, occurred_at FROM work_events
             WHERE user_id = :u AND occurred_at >= (NOW() - INTERVAL 60 DAY)
             ORDER BY occurred_at ASC, id ASC"
        );
        $stmt->execute([':u' => $userId]);

        $latest = [];
        foreach ($stmt->fetchAll() as $r) {
            $latest[(string) ($r['location'] ?? '')] = $r;
        }

        $out = [];
        foreach ($latest as $r) {
            if ((string) $r['kind'] === 'in') {
                $out[] = [
                    'place'       => $r['location'] !== null ? (string) $r['location'] : null,
                    'in_id'       => (int) $r['id'],
                    'occurred_at' => (string) $r['occurred_at'],
                ];
            }
        }

        return $out;
    }

    /**
     * Currently-open sessions (per user + workplace) that haven't been nudged yet —
     * i.e. the latest event for that (user, place) is an 'in' with no clock-out and
     * nudged_at still NULL. The cron decides which of these are worth a reminder.
     *
     * @return array<int, array{id:int, user_id:int, location:?string, occurred_at:string}>
     */
    public function openSessionsForNudge(): array
    {
        // Single scan over recent events, grouped in PHP (the table stays small for
        // a personal app, and this avoids a self-join). The latest event per
        // (user, place) that is an un-nudged 'in' is an open session to consider.
        // Bounded to 60 days so the scan can't grow unbounded; an open session
        // older than that is a stale forgotten punch handled via the hours card.
        $rows = $this->db->query(
            "SELECT id, user_id, kind, location, occurred_at, nudged_at
             FROM work_events
             WHERE occurred_at >= (NOW() - INTERVAL 60 DAY)
             ORDER BY user_id ASC, occurred_at ASC, id ASC"
        )->fetchAll();

        $latest = [];
        foreach ($rows as $r) {
            $key          = $r['user_id'] . '|' . (string) ($r['location'] ?? '');
            $latest[$key] = $r; // ascending order → last write per group is the latest event
        }

        $out = [];
        foreach ($latest as $r) {
            if ((string) $r['kind'] === 'in' && $r['nudged_at'] === null) {
                $out[] = [
                    'id'          => (int) $r['id'],
                    'user_id'     => (int) $r['user_id'],
                    'location'    => $r['location'] !== null ? (string) $r['location'] : null,
                    'occurred_at' => (string) $r['occurred_at'],
                ];
            }
        }

        return $out;
    }

    /**
     * Marks an open session's clock-in as nudged, atomically. Returns true only if
     * this call is the one that claimed it (so the reminder fires exactly once even
     * if two cron runs overlap).
     */
    public function claimNudge(int $inEventId): bool
    {
        $stmt = $this->db->prepare('UPDATE work_events SET nudged_at = NOW() WHERE id = :id AND nudged_at IS NULL');
        $stmt->execute([':id' => $inEventId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Summarises worked time over a scope ('today' | 'yesterday' | 'week' |
     * 'lastweek') or an
     * explicit local date (YYYY-MM-DD). Returns totals, the sessions overlapping
     * the range, any forgotten clock-outs to fix, and a renderable card.
     *
     * @return array{
     *   scope:string, range_label:string, total_minutes:int, total_label:string,
     *   ongoing:bool, sessions:array<int,array<string,mixed>>,
     *   needs_fix:array<int,array<string,mixed>>, card:array<string,mixed>
     * }
     */
    public function summary(int $userId, string $scope = 'today', ?string $date = null, ?string $place = null): array
    {
        $tz  = new DateTimeZone(self::LOCAL_TZ);
        $utc = new DateTimeZone('UTC');
        [$startLocal, $endLocal, $rangeLabel, $scopeLabel] = $this->rangeFor($scope, $date, $tz);

        $fromUtc = $startLocal->setTimezone($utc);
        $toUtc   = $endLocal->setTimezone($utc);
        // Widen the query back a little to catch an 'in' that started before the range.
        $queryFrom = $fromUtc->modify('-18 hours');

        $events   = $this->eventsBetween($userId, $queryFrom->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s'));
        $sessions = $this->pairByLocation($events); // pairs per workplace, tags each session with its place

        $filterKey = ($place !== null && trim($place) !== '') ? $this->locKey($place) : null;

        $nowUtc          = new DateTimeImmutable('now', $utc);
        $totalMinutes    = 0;
        $ongoing         = false;
        $displaySessions = [];
        $needsFix        = [];
        $perLoc          = []; // locKey => ['place'=>label, 'minutes'=>int]

        foreach ($sessions as $s) {
            if ($filterKey !== null && $this->locKey($s['location']) !== $filterKey) {
                continue;
            }
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
                            'place' => $s['location'],
                            'day'   => $this->toLocal($s['in'])->format('D j M'),
                            'in'    => $this->toLocal($s['in'])->format('H:i'),
                        ];
                    }
                    continue;
                }
            }

            // Overlap of [in, end] with the range → the minutes that count.
            $ovStart  = max($in->getTimestamp(), $fromUtc->getTimestamp());
            $ovEnd    = min($end->getTimestamp(), $toUtc->getTimestamp());
            $inRange  = $ovEnd > $ovStart ? (int) round(($ovEnd - $ovStart) / 60) : 0;
            $totalMinutes += $inRange;
            if ($inRange > 0) {
                $k = $this->locKey($s['location']);
                $perLoc[$k] ??= ['place' => $s['location'], 'minutes' => 0];
                $perLoc[$k]['minutes'] += $inRange;
            }

            // Show sessions that started within the range.
            if ($in >= $fromUtc && $in < $toUtc) {
                $mins = (int) round((($end->getTimestamp()) - $in->getTimestamp()) / 60);
                $displaySessions[] = [
                    'in_id'    => $s['in_id'],
                    'out_id'   => $s['out_id'],
                    'place'    => $s['location'],
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

        // Per-workplace breakdown (only meaningful when >1 labelled place appears).
        $places = [];
        foreach ($perLoc as $row) {
            $places[] = ['place' => $row['place'], 'total' => self::fmtDuration($row['minutes']), 'minutes' => $row['minutes']];
        }
        usort($places, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);
        $labelledCount = count(array_filter($places, static fn (array $p): bool => trim((string) $p['place']) !== ''));
        $multiPlace    = $labelledCount > 1;

        $totalLabel = self::fmtDuration($totalMinutes);
        $card = [
            'kind'        => 'work_hours',
            'title'       => $scopeLabel . ($filterKey !== null ? ' · ' . $place : ''),
            'range'       => $rangeLabel,
            'total'       => $totalLabel,
            'ongoing'     => $ongoing,
            'multi_place' => $multiPlace,
            'places'      => $multiPlace
                ? array_map(static fn (array $p): array => ['place' => $p['place'], 'total' => $p['total']], $places)
                : [],
            'sessions'    => array_map(static fn (array $s): array => [
                'day'      => $s['day'],
                'place'    => $s['place'],
                'in'       => $s['in'],
                'out'      => $s['out'],
                'ongoing'  => $s['ongoing'],
                'duration' => $s['duration'],
            ], $displaySessions),
            'needs_fix'   => array_map(static fn (array $f): array => [
                'day' => $f['day'], 'in' => $f['in'], 'place' => $f['place'],
            ], $needsFix),
        ];

        return [
            'scope'         => $scope,
            'range_label'   => $rangeLabel,
            'total_minutes' => $totalMinutes,
            'total_label'   => $totalLabel,
            'ongoing'       => $ongoing,
            'sessions'      => $displaySessions,
            'needs_fix'     => $needsFix,
            'places'        => $places,
            'card'          => $card,
        ];
    }

    /**
     * Buckets worked minutes over a longer span for the bar chart: daily bars for the
     * current week, weekly bars for 4w/12w, or monthly bars for a year. A session's
     * minutes are split across whichever buckets it overlaps (so a shift crossing a
     * boundary is counted correctly); forgotten clock-outs are skipped, a live session
     * counts up to now. Returns the renderable card payload directly.
     *
     * @return array<string, mixed>
     */
    public function breakdown(int $userId, string $mode = 'week'): array
    {
        $mode = in_array($mode, self::CHART_MODES, true) ? $mode : 'week';
        $tz   = new DateTimeZone(self::LOCAL_TZ);
        $utc  = new DateTimeZone('UTC');

        [$buckets, $title, $rangeLabel, $bucketWord] = $this->chartBuckets($mode, $tz);

        $spanStart = $buckets[0]['start']->setTimezone($utc);
        $spanEnd   = $buckets[count($buckets) - 1]['end']->setTimezone($utc);
        $queryFrom = $spanStart->modify('-18 hours');

        $events   = $this->eventsBetween($userId, $queryFrom->format('Y-m-d H:i:s'), $spanEnd->format('Y-m-d H:i:s'));
        $sessions = $this->pairByLocation($events);
        $nowUtc   = new DateTimeImmutable('now', $utc);

        // Precompute each bucket's UTC window once.
        foreach ($buckets as $i => $b) {
            $buckets[$i]['minutes'] = 0;
            $buckets[$i]['ongoing'] = false;
            $buckets[$i]['from_ts'] = $b['start']->setTimezone($utc)->getTimestamp();
            $buckets[$i]['to_ts']   = $b['end']->setTimezone($utc)->getTimestamp();
        }

        $perLoc       = [];
        $totalMinutes = 0;

        foreach ($sessions as $s) {
            $in  = new DateTimeImmutable($s['in'], $utc);
            $out = $s['out'] !== null ? new DateTimeImmutable($s['out'], $utc) : null;

            $end       = $out;
            $isOngoing = false;
            if ($out === null) {
                if (($nowUtc->getTimestamp() - $in->getTimestamp()) <= self::STALE_OPEN_HOURS * 3600) {
                    $end       = $nowUtc;
                    $isOngoing = true;
                } else {
                    continue; // forgotten clock-out — don't count
                }
            }

            $inTs  = $in->getTimestamp();
            $endTs = $end->getTimestamp();
            foreach ($buckets as $i => $b) {
                $ovStart = max($inTs, $b['from_ts']);
                $ovEnd   = min($endTs, $b['to_ts']);
                if ($ovEnd <= $ovStart) {
                    continue;
                }
                $mins = (int) round(($ovEnd - $ovStart) / 60);
                if ($mins <= 0) {
                    continue;
                }
                $buckets[$i]['minutes'] += $mins;
                $totalMinutes           += $mins;
                if ($isOngoing && $endTs > $b['from_ts'] && $endTs <= $b['to_ts']) {
                    $buckets[$i]['ongoing'] = true;
                }
                $k          = $this->locKey($s['location']);
                $perLoc[$k] ??= ['place' => $s['location'], 'minutes' => 0];
                $perLoc[$k]['minutes'] += $mins;
            }
        }

        $bars = array_map(static fn (array $b): array => [
            'label'   => $b['label'],
            'sub'     => $b['sub'],
            'minutes' => $b['minutes'],
            'total'   => self::fmtDuration($b['minutes']),
            'ongoing' => $b['ongoing'],
        ], $buckets);

        $places = [];
        foreach ($perLoc as $row) {
            if ($row['minutes'] > 0) {
                $places[] = ['place' => $row['place'], 'total' => self::fmtDuration($row['minutes']), 'minutes' => $row['minutes']];
            }
        }
        usort($places, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);
        $labelled   = array_filter($places, static fn (array $p): bool => trim((string) $p['place']) !== '');
        $multiPlace = count($labelled) > 1;

        $active  = count(array_filter($buckets, static fn (array $b): bool => $b['minutes'] > 0));
        $avgMin  = $active > 0 ? (int) round($totalMinutes / $active) : 0;

        return [
            'kind'          => 'work_chart',
            'mode'          => $mode,
            'modes'         => [
                ['key' => 'week', 'label' => 'Week'],
                ['key' => '4w',   'label' => '4 wks'],
                ['key' => '12w',  'label' => '12 wks'],
                ['key' => 'year', 'label' => 'Year'],
            ],
            'title'         => $title,
            'range'         => $rangeLabel,
            'unit'          => 'h',
            'bucket_word'   => $bucketWord,
            'bars'          => $bars,
            'total'         => self::fmtDuration($totalMinutes),
            'total_minutes' => $totalMinutes,
            'avg'           => self::fmtDuration($avgMin),
            'places'        => $multiPlace
                ? array_map(static fn (array $p): array => ['place' => $p['place'], 'total' => $p['total']], $places)
                : [],
            'has_data'      => $totalMinutes > 0,
        ];
    }

    /**
     * Builds the ordered list of chart buckets (each with local start/end + labels)
     * plus the card title, range label and the word for one bucket ("day"/"week"/"month").
     *
     * @return array{0: array<int, array{start:DateTimeImmutable, end:DateTimeImmutable, label:string, sub:string}>, 1:string, 2:string, 3:string}
     */
    private function chartBuckets(string $mode, DateTimeZone $tz): array
    {
        $now     = new DateTimeImmutable('now', $tz);
        $buckets = [];

        if ($mode === 'week') {
            $dow = (int) $now->format('N');
            $mon = $now->setTime(0, 0)->modify('-' . ($dow - 1) . ' days');
            for ($i = 0; $i < 7; $i++) {
                $start     = $mon->modify("+{$i} days");
                $buckets[] = ['start' => $start, 'end' => $start->modify('+1 day'), 'label' => $start->format('D'), 'sub' => $start->format('j M')];
            }
            $range = $mon->format('j M') . ' – ' . $mon->modify('+6 days')->format('j M');
            return [$buckets, 'This week', $range, 'day'];
        }

        if ($mode === '4w' || $mode === '12w') {
            $n       = $mode === '4w' ? 4 : 12;
            $dow     = (int) $now->format('N');
            $thisMon = $now->setTime(0, 0)->modify('-' . ($dow - 1) . ' days');
            for ($i = $n - 1; $i >= 0; $i--) {
                $start     = $thisMon->modify("-{$i} weeks");
                $buckets[] = ['start' => $start, 'end' => $start->modify('+7 days'), 'label' => 'W' . $start->format('W'), 'sub' => $start->format('j M')];
            }
            $range = $buckets[0]['start']->format('j M') . ' – ' . $buckets[$n - 1]['end']->modify('-1 day')->format('j M');
            return [$buckets, "Last {$n} weeks", $range, 'week'];
        }

        // year — 12 calendar months ending with the current one.
        $first = $now->modify('first day of this month')->setTime(0, 0);
        for ($i = 11; $i >= 0; $i--) {
            $start     = $first->modify("-{$i} months");
            $buckets[] = ['start' => $start, 'end' => $start->modify('+1 month'), 'label' => $start->format('M'), 'sub' => $start->format('Y')];
        }
        $range = $buckets[0]['start']->format('M Y') . ' – ' . $buckets[11]['start']->format('M Y');
        return [$buckets, 'Last 12 months', $range, 'month'];
    }

    /**
     * Groups events per workplace and pairs each group into sessions, so a
     * clock-out at one place doesn't close an open session at another. Each session
     * is tagged with its place label (null if unlabelled).
     *
     * @param array<int,array{id:int,kind:string,occurred_at:string,location:?string}> $events ordered asc
     * @return array<int,array{in:string, out:?string, in_id:int, out_id:?int, location:?string}>
     */
    private function pairByLocation(array $events): array
    {
        $groups = [];
        foreach ($events as $e) {
            $groups[$this->locKey($e['location'] ?? null)][] = $e;
        }

        $sessions = [];
        foreach ($groups as $groupEvents) {
            $label = null;
            foreach ($groupEvents as $ge) {
                if (trim((string) ($ge['location'] ?? '')) !== '') {
                    $label = (string) $ge['location'];
                    break;
                }
            }
            foreach ($this->pair($groupEvents) as $sess) {
                $sess['location'] = $label;
                $sessions[]       = $sess;
            }
        }

        usort($sessions, static fn (array $a, array $b): int => strcmp($a['in'], $b['in']));

        return $sessions;
    }

    private function locKey(?string $s): string
    {
        return strtolower(trim((string) $s));
    }

    /**
     * Pairs a time-ordered (single-workplace) event list into sessions. Rule: the
     * first 'in' opens a session; a following 'out' closes it; extra 'in's while
     * open and extra 'out's while closed are ignored (dedup/misfire tolerance). A
     * trailing open 'in' is returned with out=null.
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
     * @return array<int,array{id:int, kind:string, occurred_at:string, location:?string}>
     */
    private function eventsBetween(int $userId, string $fromUtc, string $toUtc): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, kind, occurred_at, location FROM work_events
             WHERE user_id = :u AND occurred_at >= :from AND occurred_at < :to
             ORDER BY occurred_at ASC, id ASC'
        );
        $stmt->execute([':u' => $userId, ':from' => $fromUtc, ':to' => $toUtc]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'kind'        => (string) $r['kind'],
                'occurred_at' => (string) $r['occurred_at'],
                'location'    => $r['location'] !== null ? (string) $r['location'] : null,
            ];
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

        if ($scope === 'week' || $scope === 'lastweek') {
            $dow     = (int) $now->format('N'); // 1 = Mon
            $thisMon = $now->setTime(0, 0)->modify('-' . ($dow - 1) . ' days');
            $start   = $scope === 'lastweek' ? $thisMon->modify('-7 days') : $thisMon;
            $end     = $start->modify('+7 days');
            $label   = $scope === 'lastweek' ? 'Last week' : 'This week';
            return [$start, $end, $start->format('j M') . ' – ' . $end->modify('-1 day')->format('j M'), $label];
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
