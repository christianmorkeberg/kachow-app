<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The card that shows a training programme for checking: its weeks, each with its days and their exercises as one-line labels, and
 * where "today" falls. Pure — built from TrainingPrograms::days().
 */
final class ProgramCard
{
    private const NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @param array<string, mixed> $program one of TrainingPrograms::all()
     * @param list<array{week:int, year:int, weekday:int, title:?string, note:?string, items:list<array<string, mixed>>}> $days
     * @return array<string, mixed>
     */
    public static function build(array $program, array $days, ?DateTimeImmutable $now = null, string $tz = 'Europe/Copenhagen'): array
    {
        $now   = $now ?? new DateTimeImmutable('now', new DateTimeZone($tz));
        $weeks = [];
        foreach ($days as $d) {
            $key = $d['year'] . '-' . $d['week'];
            $weeks[$key] ??= ['week' => $d['week'], 'year' => $d['year'], 'days' => []];
            $weeks[$key]['days'][] = [
                'weekday' => $d['weekday'], 'name' => self::NAMES[$d['weekday']] ?? '', 'title' => $d['title'], 'note' => $d['note'],
                'items'   => array_map([ProgramInput::class, 'itemLabel'], $d['items']),
            ];
        }
        $thisWeek = \App\Data\TrainingPrograms::weekFor($program, $now);

        return [
            'kind'    => 'training_program',
            'title'   => $program['name'],
            'program' => [
                'id' => $program['id'], 'name' => $program['name'], 'status' => $program['status'], 'week_mode' => $program['week_mode'],
                'start_date' => $program['start_date'], 'repeat_weeks' => $program['repeat_weeks'],
            ],
            'weeks'   => array_values($weeks),
            'today'   => ['week' => $thisWeek, 'weekday' => (int) $now->format('N')],
        ];
    }
}
