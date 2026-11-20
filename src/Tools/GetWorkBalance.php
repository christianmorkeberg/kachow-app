<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkSchedule;

/** Tool: how far ahead or behind the work schedule the user is (worked + days off − target), plus the schedule and the days off marked. */
final class GetWorkBalance implements Tool
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function name(): string
    {
        return 'get_work_balance';
    }

    public function description(): string
    {
        return 'Shows whether the user is ahead or behind their work schedule, PER WORKPLACE that has a schedule (a workplace without one — freelance — is not counted): worked hours there + days off (holiday, sick, public holidays) minus the scheduled hours, from the schedule\'s start date '
            . '(opening balance 0) up to yesterday, plus today so far and a per-month breakdown. Use for "am I ahead or behind?", "flex balance", "how many hours do I have in plus/minus", "overtid/afspadsering". '
            . 'Also returns the schedule and the coming days off. `since` limits the count to a later start; `place` to one workplace. If nothing is configured, offer to set it up with set_work_schedule.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'since' => ['type' => 'string', 'description' => 'Count only from this date YYYY-MM-DD (default: the schedule start).'],
                'place' => ['type' => 'string', 'description' => 'One workplace only.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->schedule->available()) {
            return ['configured' => false, 'note' => 'The work schedule is not set up on the server yet (migration 2026-11-18_work_schedule.sql).'];
        }
        $place  = isset($arguments['place']) && trim((string) $arguments['place']) !== '' ? (string) $arguments['place'] : null;
        $scopes = $this->schedule->balances($userId, isset($arguments['since']) ? (string) $arguments['since'] : null, $place);
        if ($scopes === []) {
            return ['configured' => false, 'note' => $place !== null
                ? 'There is no schedule for "' . $place . '" (workplaces without a schedule have no balance). Existing schedules: ' . json_encode(array_map(static fn (array $s): array => ['place' => $s['place'], 'per_week' => $s['per_week'], 'from' => $s['from']], $this->schedule->schedules($userId)))
                : 'No work schedule yet. Ask how many hours per week and which days, and from when, then call set_work_schedule.'];
        }
        $fmt = static fn (int $m): string => intdiv(abs($m), 60) . 'h ' . str_pad((string) (abs($m) % 60), 2, '0', STR_PAD_LEFT) . 'm';
        $lab = static fn (array $x): array => ['worked' => $fmt($x['worked']), 'days_off_credited' => $fmt($x['credited']), 'target' => $fmt($x['target']), 'balance' => self::signed($x['balance'])];
        $out = [];
        foreach ($scopes as $sc) {
            $b     = $sc['balance'];
            $out[] = [
                'workplace' => $sc['place'] ?? 'all workplaces (combined schedule)',
                'balance'   => self::signed($b['totals']['balance']),
                'counted'   => $b['from'] . ' – ' . $b['through'] . ' (opening balance 0 on ' . $b['from'] . ')',
                'totals'    => $lab($b['totals']),
                'by_month'  => array_map(static fn (array $m): array => ['month' => $m['month']] + $lab($m), $b['months']),
                'today'     => ['target' => $fmt($b['today']['target']), 'worked_so_far' => $fmt($b['today']['worked']), 'day_off_credit' => $fmt($b['today']['credited'])],
            ];
        }

        return [
            'configured'      => true,
            'balances'        => $out,
            'note'            => 'One balance PER schedule scope — each workplace with a schedule is compared only with the hours worked THERE. Work at a workplace with no schedule (freelance) is not counted. Never add the balances together.',
            'schedule'        => $this->schedule->schedules($userId),
            'coming_days_off' => array_slice($this->schedule->absences($userId, \App\Support\LocalTime::today(), null), 0, 20),
        ];
    }

    /** "+3h 12m" / "−0h 40m" */
    public static function signed(int $minutes): string
    {
        $a = abs($minutes);

        return ($minutes < 0 ? '−' : '+') . intdiv($a, 60) . 'h ' . str_pad((string) ($a % 60), 2, '0', STR_PAD_LEFT) . 'm';
    }
}
