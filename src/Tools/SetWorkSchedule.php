<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkSchedule;
use App\Support\LocalTime;

/** Tool: tell the assistant what the user is meant to work (hours per weekday from a date). The schedule is data the user gives; nothing is built in. */
final class SetWorkSchedule implements Tool
{
    private const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function name(): string
    {
        return 'set_work_schedule';
    }

    public function description(): string
    {
        return 'Sets the work schedule used to track being ahead/behind: how many hours the user is meant to work per weekday, from a date. Either `hours_per_week` with the `days` they work '
            . '(split evenly: 37 h over mon–fri = 7.4 h a day), or `daily` with hours per weekday (mon..sun). `from` is the date it starts (the balance counts from the first one, opening balance 0). '
            . 'A later schedule with a new `from` replaces it from that date (a new contract, reduced hours). `place` only if this target belongs to one workplace; omit it for one combined target. '
            . 'Never assume hours: ask the user if they have not said.';
    }

    public function parameters(): array
    {
        $day = ['type' => 'number', 'description' => 'Hours that weekday.'];

        return [
            'type'       => 'object',
            'properties' => [
                'from'           => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD.'],
                'hours_per_week' => ['type' => 'number', 'description' => 'Weekly hours, split evenly over `days`.'],
                'days'           => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(self::DAYS)], 'description' => 'The weekdays worked (with hours_per_week).'],
                'daily'          => ['type' => 'object', 'description' => 'Hours per weekday instead.', 'properties' => array_fill_keys(array_keys(self::DAYS), $day)],
                'place'          => ['type' => 'string', 'description' => 'Only if the target is for one workplace.'],
                'note'           => ['type' => 'string'],
            ],
            'required' => ['from'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->schedule->available()) {
            return ['saved' => false, 'error' => 'The work schedule is not set up on the server yet (migration 2026-11-18_work_schedule.sql).'];
        }
        $from = (string) ($arguments['from'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            return ['saved' => false, 'error' => 'Give the start date as YYYY-MM-DD.'];
        }
        $hours = [];
        if (is_array($arguments['daily'] ?? null)) {
            foreach ($arguments['daily'] as $k => $h) {
                if (isset(self::DAYS[strtolower((string) $k)])) {
                    $hours[self::DAYS[strtolower((string) $k)]] = (float) $h;
                }
            }
        } elseif (isset($arguments['hours_per_week']) && is_array($arguments['days'] ?? null) && $arguments['days'] !== []) {
            $ds = array_values(array_filter(array_map(static fn ($d): ?int => self::DAYS[strtolower((string) $d)] ?? null, $arguments['days'])));
            foreach ($ds as $d) {
                $hours[$d] = round((float) $arguments['hours_per_week'] / count($ds), 2);
            }
        }
        if ($hours === []) {
            return ['saved' => false, 'error' => 'Give either hours_per_week with the days worked, or daily hours per weekday.'];
        }
        $place = isset($arguments['place']) ? (string) $arguments['place'] : null;
        $this->schedule->setSchedule($userId, $from, $hours, $place, isset($arguments['note']) ? (string) $arguments['note'] : null);

        return ['saved' => true, 'from' => $from, 'place' => $place, 'hours' => $hours, 'per_week' => round(array_sum($hours), 2),
            'note' => 'The balance now counts from the first schedule\'s start date with an opening balance of 0. Use get_work_balance to see it.'];
    }
}
