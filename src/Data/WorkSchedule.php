<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\LocalTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The work schedule (what the user is meant to work, as DATA they give — hours per weekday from a date, optionally per workplace), days off that credit hours,
 * and the balance: worked + credited − target since the first schedule's start date, opening balance 0. Owner-scoped. Tolerant of missing tables (before the
 * migration): available() is false and everything reads as empty.
 */
final class WorkSchedule
{
    private PDO $db;
    private ?bool $ok = null;
    public const KINDS = ['holiday', 'sick', 'public_holiday', 'other'];

    public function __construct(?PDO $db = null, private ?WorkEvents $events = null)
    {
        $this->db     = $db ?? Database::get();
        $this->events = $events ?? new WorkEvents($this->db);
    }

    public function available(): bool
    {
        if ($this->ok === null) {
            try {
                $this->db->query('SELECT 1 FROM work_schedule LIMIT 1');
                $this->db->query('SELECT 1 FROM work_absences LIMIT 1');
                $this->ok = true;
            } catch (\Throwable) {
                $this->ok = false;
            }
        }

        return $this->ok;
    }

    /** @param array<int|string, float|int> $hours ISO weekday (1–7) => hours */
    public function setSchedule(int $userId, string $from, array $hours, ?string $place = null, ?string $note = null): void
    {
        $clean = [];
        foreach ($hours as $d => $h) {
            if ((int) $d >= 1 && (int) $d <= 7 && is_numeric($h) && (float) $h > 0 && (float) $h <= 24) {
                $clean[(string) (int) $d] = round((float) $h, 2);
            }
        }
        $place = $place !== null && trim($place) !== '' ? mb_substr(trim($place), 0, 64) : null;
        $this->db->prepare('DELETE FROM work_schedule WHERE user_id = :u AND effective_from = :f AND ' . ($place === null ? 'place IS NULL' : 'place = :p'))
            ->execute($place === null ? [':u' => $userId, ':f' => $from] : [':u' => $userId, ':f' => $from, ':p' => $place]);
        $this->db->prepare('INSERT INTO work_schedule (user_id, effective_from, place, hours, note) VALUES (:u, :f, :p, :h, :n)')
            ->execute([':u' => $userId, ':f' => $from, ':p' => $place, ':h' => json_encode($clean), ':n' => $note !== null ? mb_substr($note, 0, 255) : null]);
    }

    /** @return list<array{id:int, from:string, place:?string, hours:array<string,float>, per_week:float, note:?string}> */
    public function schedules(int $userId): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->db->prepare('SELECT id, effective_from, place, hours, note FROM work_schedule WHERE user_id = :u ORDER BY effective_from, place');
        $st->execute([':u' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $h     = json_decode((string) $r['hours'], true) ?: [];
            $out[] = ['id' => (int) $r['id'], 'from' => (string) $r['effective_from'], 'place' => $r['place'] !== null ? (string) $r['place'] : null,
                'hours' => array_map('floatval', $h), 'per_week' => round(array_sum(array_map('floatval', $h)), 2), 'note' => $r['note'] !== null ? (string) $r['note'] : null];
        }

        return $out;
    }

    /**
     * Target MINUTES for a local date: for every workplace group, the latest schedule row starting on or before the date counts; the groups add up.
     * With $place only that workplace's own rows.
     *
     * @param list<array<string, mixed>> $schedules from schedules()
     */
    public static function targetMinutes(array $schedules, string $date, ?string $place = null): int
    {
        $wd = (int) (new DateTimeImmutable($date, new DateTimeZone(LocalTime::TZ)))->format('N');
        $latest = [];
        foreach ($schedules as $s) {
            if ($s['from'] > $date) {
                continue;
            }
            $key = mb_strtolower((string) ($s['place'] ?? ''));
            if ($place !== null && trim($place) !== '' && $key !== mb_strtolower(trim($place))) {
                continue;
            }
            if (!isset($latest[$key]) || $s['from'] >= $latest[$key]['from']) {
                $latest[$key] = $s;
            }
        }
        $min = 0;
        foreach ($latest as $s) {
            $min += (int) round(((float) ($s['hours'][(string) $wd] ?? 0)) * 60);
        }

        return $min;
    }

    /**
     * Marks days off. Dates without a target (weekends, days off in the schedule) are skipped unless `hours` is given, so a holiday week does not "credit" Saturday.
     *
     * @param list<string> $dates
     * @return array{added:list<string>, skipped:list<string>}
     */
    public function addAbsences(int $userId, array $dates, string $kind, ?float $hours = null, ?string $place = null, ?string $note = null): array
    {
        $kind  = in_array($kind, self::KINDS, true) ? $kind : 'other';
        $place = $place !== null && trim($place) !== '' ? mb_substr(trim($place), 0, 64) : null;
        $sch   = $this->schedules($userId);
        $added = [];
        $skipped = [];
        foreach ($dates as $d) {
            if ($hours === null && self::targetMinutes($sch, $d, $place) === 0) {
                $skipped[] = $d;
                continue;
            }
            $this->db->prepare('DELETE FROM work_absences WHERE user_id = :u AND absence_date = :d AND ' . ($place === null ? 'place IS NULL' : 'place = :p'))
                ->execute($place === null ? [':u' => $userId, ':d' => $d] : [':u' => $userId, ':d' => $d, ':p' => $place]);
            $this->db->prepare('INSERT INTO work_absences (user_id, absence_date, kind, hours, place, note) VALUES (:u, :d, :k, :h, :p, :n)')
                ->execute([':u' => $userId, ':d' => $d, ':k' => $kind, ':h' => $hours, ':p' => $place, ':n' => $note !== null ? mb_substr($note, 0, 255) : null]);
            $added[] = $d;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return list<array{id:int, date:string, kind:string, hours:?float, place:?string, note:?string}> */
    public function absences(int $userId, ?string $from = null, ?string $to = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $st = $this->db->prepare('SELECT id, absence_date, kind, hours, place, note FROM work_absences WHERE user_id = :u AND absence_date >= :f AND absence_date <= :t ORDER BY absence_date');
        $st->execute([':u' => $userId, ':f' => $from ?? '2000-01-01', ':t' => $to ?? '2100-01-01']);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'date' => (string) $r['absence_date'], 'kind' => (string) $r['kind'],
            'hours' => $r['hours'] !== null ? (float) $r['hours'] : null, 'place' => $r['place'] !== null ? (string) $r['place'] : null, 'note' => $r['note'] !== null ? (string) $r['note'] : null], $st->fetchAll());
    }

    /** @param list<int> $ids @return list<array<string, mixed>> the removed rows (for the change log) */
    public function deleteAbsences(int $userId, array $ids): array
    {
        $gone = [];
        foreach ($ids as $id) {
            $st = $this->db->prepare('SELECT id, absence_date, kind, hours, place, note FROM work_absences WHERE id = :id AND user_id = :u');
            $st->execute([':id' => (int) $id, ':u' => $userId]);
            $r = $st->fetch();
            if ($r !== false) {
                $this->db->prepare('DELETE FROM work_absences WHERE id = :id AND user_id = :u')->execute([':id' => (int) $id, ':u' => $userId]);
                $gone[] = $r;
            }
        }

        return $gone;
    }

    /**
     * The balance, counted from `$since` (default: the first schedule's start) up to YESTERDAY, plus today so far. Per local day: worked + credited − target.
     * Opening balance 0 at the start date.
     *
     * @return array<string, mixed>
     */
    public function balance(int $userId, ?string $since = null, ?string $place = null, ?string $today = null): array
    {
        $sch = $this->schedules($userId);
        if ($sch === []) {
            return ['configured' => false];
        }
        $today  = $today ?? LocalTime::today();
        $first  = min(array_column($sch, 'from'));
        $from   = $since !== null && $since > $first ? $since : $first;
        $to     = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $abs    = [];
        foreach ($this->absences($userId, $from, $today) as $a) {
            if ($place === null || $a['place'] === null || mb_strtolower($a['place']) === mb_strtolower($place)) {
                $abs[$a['date']][] = $a;
            }
        }
        $worked = $from <= $today ? $this->events->minutesPerDay($userId, $from, $today, $place) : [];
        $tot = ['worked' => 0, 'credited' => 0, 'target' => 0];
        $byMonth = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d');
            $t = self::targetMinutes($sch, $k, $place);
            $c = $this->credit($abs[$k] ?? [], $t);
            $w = $worked[$k] ?? 0;
            $m = $d->format('Y-m');
            $byMonth[$m] ??= ['worked' => 0, 'credited' => 0, 'target' => 0];
            foreach (['worked' => $w, 'credited' => $c, 'target' => $t] as $key => $v) {
                $tot[$key] += $v;
                $byMonth[$m][$key] += $v;
            }
        }
        $todayT = self::targetMinutes($sch, $today, $place);
        $todayC = $this->credit($abs[$today] ?? [], $todayT);
        $fmtM   = static fn (array $x): array => $x + ['balance' => $x['worked'] + $x['credited'] - $x['target']];
        $months = [];
        foreach ($byMonth as $m => $x) {
            $months[] = ['month' => $m] + $fmtM($x);
        }

        return [
            'configured' => true,
            'from'       => $from,
            'through'    => $to,
            'since_note' => 'Opening balance 0 on ' . $from . '.',
            'totals'     => $fmtM($tot),
            'months'     => $months,
            'today'      => ['target' => $todayT, 'credited' => $todayC, 'worked' => $worked[$today] ?? 0],
        ];
    }

    /** @param list<array<string, mixed>> $rows that day's absences @return int minutes credited: a full-day mark credits the day's target (once), a part-day mark its hours */
    private function credit(array $rows, int $targetMinutes): int
    {
        $explicit = 0;
        $full     = false;
        foreach ($rows as $a) {
            if ($a['hours'] !== null) {
                $explicit += (int) round($a['hours'] * 60);
            } else {
                $full = true;
            }
        }

        return $full ? max($targetMinutes, $explicit) : $explicit;
    }
}
