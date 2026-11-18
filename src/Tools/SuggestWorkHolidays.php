<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkSchedule;
use App\Support\DanishHolidays;
use App\Support\LocalTime;

/**
 * Tool: the Danish public holidays that fall on scheduled working days and are not marked yet — SUGGESTIONS. Nothing is marked until the user confirms; then add_work_absence
 * with kind public_holiday.
 */
final class SuggestWorkHolidays implements Tool
{
    public function __construct(private WorkSchedule $schedule)
    {
    }

    public function name(): string
    {
        return 'suggest_work_holidays';
    }

    public function description(): string
    {
        return 'Lists Danish public holidays (Easter-based ones included) that fall on scheduled working days and are not marked as days off yet, as SUGGESTIONS — show them and ask which to mark. '
            . 'Only after the user says yes call add_work_absence (kind public_holiday) for the confirmed dates. `common` ones (Constitution Day, Christmas Eve, the day after Ascension Day, New Year\'s Eve) '
            . 'are days many employers give off but not public holidays: mention them separately. Defaults to the rest of this year.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'year' => ['type' => 'integer', 'description' => 'The year (default: this year, from today).'],
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (default: today, or Jan 1 for a given year).'],
                'to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (default: Dec 31).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->schedule->available()) {
            return ['available' => false, 'note' => 'The work schedule is not set up on the server yet (migration 2026-11-18_work_schedule.sql).'];
        }
        $year = isset($arguments['year']) ? (int) $arguments['year'] : LocalTime::year();
        $from = isset($arguments['from']) ? (string) $arguments['from'] : (isset($arguments['year']) ? $year . '-01-01' : LocalTime::today());
        $to   = isset($arguments['to']) ? (string) $arguments['to'] : $year . '-12-31';
        $sch  = $this->schedule->schedules($userId);
        $have = array_flip(array_column($this->schedule->absences($userId, $from, $to), 'date'));
        $out  = ['public' => [], 'often_off' => []];
        foreach (DanishHolidays::forYear($year) as $h) {
            if ($h['date'] < $from || $h['date'] > $to || isset($have[$h['date']])) {
                continue;
            }
            $min = WorkSchedule::targetMinutes($sch, $h['date']);
            if ($min === 0) {
                continue;   // not a working day for them anyway
            }
            $out[$h['common'] ? 'often_off' : 'public'][] = ['date' => $h['date'], 'name' => $h['name'], 'weekday' => (new \DateTimeImmutable($h['date']))->format('D'), 'scheduled_hours' => round($min / 60, 2)];
        }

        return $out + ['note' => $sch === [] ? 'No schedule set yet, so every day may be a working day: ask for the schedule first.' : 'Suggestions only — nothing is marked until the user confirms.'];
    }
}
