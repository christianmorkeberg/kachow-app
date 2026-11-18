<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ChangeLog;
use App\Data\WorkSchedule;
use DateTimeImmutable;

/** Tool: mark days off (holiday, sick, public holiday, other) so they credit the day\'s hours instead of putting the user behind. */
final class AddWorkAbsence implements Tool
{
    public function __construct(private WorkSchedule $schedule, private ?ChangeLog $log = null)
    {
    }

    public function name(): string
    {
        return 'add_work_absence';
    }

    public function description(): string
    {
        return 'Marks days off work — holiday ("I am on holiday 22–24 December"), sick, public_holiday, or other — so each day credits its scheduled hours and does not put the user behind. '
            . 'Give `from` and `to` (a range; days with no scheduled hours, like weekends, are skipped) or a single `from`. `hours` only for a PART of a day off (e.g. 4). '
            . 'For public holidays, only after the user has confirmed the suggestions from suggest_work_holidays. `place` only if it applies to one workplace.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from'  => ['type' => 'string', 'description' => 'First day YYYY-MM-DD.'],
                'to'    => ['type' => 'string', 'description' => 'Last day YYYY-MM-DD (default: same as from).'],
                'dates' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Instead of a range: specific dates.'],
                'kind'  => ['type' => 'string', 'enum' => WorkSchedule::KINDS],
                'hours' => ['type' => 'number', 'description' => 'Hours credited for a part-day absence; omit for the full day.'],
                'place' => ['type' => 'string'],
                'note'  => ['type' => 'string'],
            ],
            'required' => ['kind'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->schedule->available()) {
            return ['saved' => false, 'error' => 'The work schedule is not set up on the server yet (migration 2026-11-18_work_schedule.sql).'];
        }
        $dates = [];
        foreach ((array) ($arguments['dates'] ?? []) as $d) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) === 1) {
                $dates[] = (string) $d;
            }
        }
        if ($dates === []) {
            $from = (string) ($arguments['from'] ?? '');
            $to   = (string) ($arguments['to'] ?? $from);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from) {
                return ['saved' => false, 'error' => 'Give from (and to) as YYYY-MM-DD, or dates.'];
            }
            for ($d = new DateTimeImmutable($from), $n = 0; $d->format('Y-m-d') <= $to && $n < 400; $d = $d->modify('+1 day'), $n++) {
                $dates[] = $d->format('Y-m-d');
            }
        }
        if ($this->schedule->schedules($userId) === []) {
            return ['saved' => false, 'error' => 'There is no work schedule yet, so there are no hours to credit. Ask for the schedule and call set_work_schedule first.'];
        }
        $kind  = (string) ($arguments['kind'] ?? 'holiday');
        $hours = isset($arguments['hours']) && is_numeric($arguments['hours']) ? (float) $arguments['hours'] : null;
        $r     = $this->schedule->addAbsences($userId, $dates, $kind, $hours, isset($arguments['place']) ? (string) $arguments['place'] : null, isset($arguments['note']) ? (string) $arguments['note'] : null);

        return ['saved' => $r['added'] !== [], 'days_marked' => count($r['added']), 'dates' => $r['added'], 'skipped_no_scheduled_hours' => $r['skipped'], 'kind' => $kind];
    }
}
