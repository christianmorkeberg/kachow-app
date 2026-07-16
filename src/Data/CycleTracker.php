<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Menstrual cycle tracking: log periods, and derive predictions (next period,
 * current cycle day, phase, fertile-window estimate) from the history of start
 * dates. Nothing predicted is stored — it's all computed on read.
 *
 * Sensitive health data: every query is hard-scoped to a user id (the acting user,
 * or a connection owner already cleared by ConnectionAccess with the 'cycle' scope).
 *
 * Predictions are ESTIMATES for planning, never medical or contraceptive advice —
 * the assistant is told to frame the fertile window that way.
 */
final class CycleTracker
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

    /** Sensible defaults until there's enough history to average. */
    private const DEFAULT_CYCLE  = 28;
    private const DEFAULT_PERIOD = 5;

    /** Clamp derived averages so a mis-log can't produce a nonsense cycle. */
    private const MIN_CYCLE = 20;
    private const MAX_CYCLE = 60;
    private const MIN_PERIOD = 1;
    private const MAX_PERIOD = 12;

    /** Fertile window around estimated ovulation (days before … after). */
    private const FERTILE_BEFORE = 5;
    private const FERTILE_AFTER  = 1;

    /** Stop the "register your period" reminder this many days after it was due. */
    private const REMIND_MAX_LATE = 5;

    public const FLOWS = ['light', 'medium', 'heavy'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone(self::LOCAL_TZ));
    }

    /**
     * Logs (or updates) a period by its start date. Upsert on (user, start_date) so
     * re-logging the same start just refines end/flow/note. Returns the row id.
     */
    public function logPeriod(int $userId, string $startDate, ?string $endDate = null, ?string $flow = null, ?string $note = null): int
    {
        $start = $this->normalizeDate($startDate) ?? $this->today()->format('Y-m-d');
        $end   = $this->normalizeDate($endDate);
        $flow  = $this->normalizeFlow($flow);
        $note  = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;

        // Guard against end before start.
        if ($end !== null && $end < $start) {
            $end = null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO cycle_periods (user_id, start_date, end_date, flow, note)
             VALUES (:u, :s, :e, :f, :n)
             ON DUPLICATE KEY UPDATE
                end_date = COALESCE(VALUES(end_date), end_date),
                flow     = COALESCE(VALUES(flow), flow),
                note     = COALESCE(VALUES(note), note)'
        );
        $stmt->execute([':u' => $userId, ':s' => $start, ':e' => $end, ':f' => $flow, ':n' => $note]);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        // ON DUPLICATE UPDATE path: fetch the existing row's id.
        $sel = $this->db->prepare('SELECT id FROM cycle_periods WHERE user_id = :u AND start_date = :s');
        $sel->execute([':u' => $userId, ':s' => $start]);

        return (int) $sel->fetchColumn();
    }

    /** Removes a logged period by id, owner-scoped. Returns true if a row was deleted. */
    public function remove(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM cycle_periods WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Removes the most recently started period, owner-scoped. Returns the deleted start date or null. */
    public function removeLatest(int $userId): ?string
    {
        $row = $this->db->prepare('SELECT id, start_date FROM cycle_periods WHERE user_id = :u ORDER BY start_date DESC, id DESC LIMIT 1');
        $row->execute([':u' => $userId]);
        $r = $row->fetch();
        if ($r === false) {
            return null;
        }
        $this->remove($userId, (int) $r['id']);

        return (string) $r['start_date'];
    }

    /**
     * All logged periods, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 24): array
    {
        $limit = max(1, min(60, $limit));
        $stmt  = $this->db->prepare(
            'SELECT id, start_date, end_date, flow, note FROM cycle_periods
             WHERE user_id = :u ORDER BY start_date DESC LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * The full prediction/status for a user, derived from history.
     *
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $rows = $this->listForUser($userId, 24);
        if ($rows === []) {
            return ['has_data' => false];
        }

        // Start dates, oldest→newest, for gap math.
        $starts = array_reverse(array_map(static fn (array $r): string => (string) $r['start_date'], $rows));

        // Cycle length = median gap between consecutive starts (recent gaps weighted
        // by simply using the last several). Period length = median of logged spans.
        $gaps = [];
        for ($i = 1, $n = count($starts); $i < $n; $i++) {
            $gaps[] = $this->daysBetween($starts[$i - 1], $starts[$i]);
        }
        $recentGaps = array_slice($gaps, -6);
        $cycleLen   = $recentGaps === []
            ? self::DEFAULT_CYCLE
            : $this->clamp((int) round($this->median($recentGaps)), self::MIN_CYCLE, self::MAX_CYCLE);

        $spans = [];
        foreach ($rows as $r) {
            if ($r['end_date'] !== null) {
                $spans[] = $this->daysBetween((string) $r['start_date'], (string) $r['end_date']) + 1;
            }
        }
        $periodLen = $spans === []
            ? self::DEFAULT_PERIOD
            : $this->clamp((int) round($this->median($spans)), self::MIN_PERIOD, self::MAX_PERIOD);

        $predicted = $recentGaps !== [];

        $lastStart = new DateTimeImmutable((string) $rows[0]['start_date'], new DateTimeZone(self::LOCAL_TZ));
        $today     = $this->today();
        $cycleDay  = $this->daysBetween($lastStart->format('Y-m-d'), $today->format('Y-m-d')) + 1;

        $nextStart  = $lastStart->modify('+' . $cycleLen . ' days');
        $daysUntil  = $this->daysBetween($today->format('Y-m-d'), $nextStart->format('Y-m-d'));

        // Estimated ovulation ≈ 14 days before the next period; fertile window around it.
        $ovulation  = $nextStart->modify('-14 days');
        $fertileFrom = $ovulation->modify('-' . self::FERTILE_BEFORE . ' days');
        $fertileTo   = $ovulation->modify('+' . self::FERTILE_AFTER . ' days');

        $phase = $this->phaseFor($today, $cycleDay, $periodLen, $ovulation, $fertileFrom, $fertileTo);
        $inFertile = $today >= $fertileFrom && $today <= $fertileTo;

        return [
            'has_data'      => true,
            'predicted'     => $predicted,
            'cycle_day'     => max(1, $cycleDay),
            'cycle_length'  => $cycleLen,
            'period_length' => $periodLen,
            'phase'         => $phase,
            'phase_label'   => self::phaseLabel($phase),
            'last_start'    => $lastStart->format('Y-m-d'),
            'next_period'   => $nextStart->format('Y-m-d'),
            'days_until'    => $daysUntil,   // negative ⇒ overdue
            'ovulation'     => $ovulation->format('Y-m-d'),
            'fertile_from'  => $fertileFrom->format('Y-m-d'),
            'fertile_to'    => $fertileTo->format('Y-m-d'),
            'in_fertile'    => $inFertile,
        ];
    }

    /**
     * Whether to remind the user to register their period: once there's at least one
     * logged period, the predicted next start has arrived (or is up to REMIND_MAX_LATE
     * days overdue) and no newer period has been logged. Returns details, or null when
     * no reminder is due. (Logging a new period advances the prediction, so the
     * reminder naturally stops once they register it.)
     *
     * @return array{next_period:string, days_late:int}|null
     */
    public function reminderDue(int $userId): ?array
    {
        $s = $this->status($userId);
        if (empty($s['has_data'])) {
            return null;
        }
        $daysUntil = (int) $s['days_until'];
        if ($daysUntil > 0 || $daysUntil < -self::REMIND_MAX_LATE) {
            return null; // not due yet, or too stale to keep nagging
        }

        return ['next_period' => (string) $s['next_period'], 'days_late' => -$daysUntil];
    }

    /**
     * The renderable card (kind = cycle). $owner is set for a shared/connected view
     * (read-only, no logging affordances).
     *
     * @param array{name:?string}|null $owner
     * @return array<string, mixed>
     */
    public function card(int $userId, ?array $owner = null): array
    {
        $status = $this->status($userId);
        $recent = [];
        foreach ($this->listForUser($userId, 6) as $r) {
            $len = $r['end_date'] !== null
                ? $this->daysBetween((string) $r['start_date'], (string) $r['end_date']) + 1
                : null;
            $recent[] = [
                'id'     => (int) $r['id'],
                'start'  => (string) $r['start_date'],
                'end'    => $r['end_date'] !== null ? (string) $r['end_date'] : null,
                'flow'   => $r['flow'] !== null ? (string) $r['flow'] : null,
                'length' => $len,
            ];
        }

        return [
            'kind'      => 'cycle',
            'read_only' => $owner !== null,
            'owner'     => $owner !== null ? ['name' => $owner['name'] ?? null] : null,
            'flows'     => self::FLOWS,
            'recent'    => $recent,
        ] + $status;
    }

    // ---- phase logic -------------------------------------------------------

    private function phaseFor(
        DateTimeImmutable $today,
        int $cycleDay,
        int $periodLen,
        DateTimeImmutable $ovulation,
        DateTimeImmutable $fertileFrom,
        DateTimeImmutable $fertileTo
    ): string {
        if ($cycleDay >= 1 && $cycleDay <= $periodLen) {
            return 'menstrual';
        }
        if ($today->format('Y-m-d') === $ovulation->format('Y-m-d')) {
            return 'ovulation';
        }
        if ($today >= $fertileFrom && $today <= $fertileTo) {
            return 'fertile';
        }
        if ($today < $ovulation) {
            return 'follicular';
        }

        return 'luteal';
    }

    public static function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'menstrual'  => 'Menstrual',
            'follicular' => 'Follicular',
            'fertile'    => 'Fertile window',
            'ovulation'  => 'Ovulation',
            'luteal'     => 'Luteal',
            default      => 'Cycle',
        };
    }

    // ---- helpers -----------------------------------------------------------

    private function daysBetween(string $a, string $b): int
    {
        $tz = new DateTimeZone(self::LOCAL_TZ);
        $da = new DateTimeImmutable($a, $tz);
        $db = new DateTimeImmutable($b, $tz);

        return (int) $da->diff($db)->format('%r%a');
    }

    /** @param array<int, int> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function clamp(int $v, int $lo, int $hi): int
    {
        return max($lo, min($hi, $v));
    }

    private function normalizeDate(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function normalizeFlow(?string $flow): ?string
    {
        if ($flow === null) {
            return null;
        }
        $flow = strtolower(trim($flow));

        return in_array($flow, self::FLOWS, true) ? $flow : null;
    }
}
