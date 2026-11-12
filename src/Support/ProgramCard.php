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
    /** The card when there is no programme yet: the picker opens it and says so ("empty"), instead of failing. @return array<string, mixed> */
    public static function empty(): array
    {
        return ['kind' => 'training_program', 'title' => 'Training programme', 'empty' => true, 'program' => null, 'weeks' => [], 'today' => ['week' => null, 'weekday' => null]];
    }

    /**
     * The card for the programme to show by default: the active one (else the newest), or the empty card.
     *
     * @param list<array<string, mixed>> $all the user's programmes
     * @return array<string, mixed>
     */
    public static function forUser(\App\Data\TrainingPrograms $programs, int $userId, ?DateTimeImmutable $now = null, ?\App\Data\Users $users = null): array
    {
        $all = $programs->all($userId);
        if ($all === []) {
            return self::empty();
        }
        $p = array_values(array_filter($all, static fn (array $x): bool => $x['status'] === 'active'))[0] ?? $all[0];

        return self::build($p, $programs->days($userId, $p['id']), $now, 'Europe/Copenhagen', self::people($programs, $p, $users), $userId);
    }

    /** The people on a shared programme as [id => first name] (empty for a programme that is only one person's). @return array<int, string> */
    public static function people(\App\Data\TrainingPrograms $programs, array $program, ?\App\Data\Users $users): array
    {
        if (($program['connection_id'] ?? null) === null || $users === null) {
            return [];
        }
        $out = [];
        foreach ($programs->participants($program) as $id) {
            $u    = $users->findById($id);
            $name = trim((string) ($u['name'] ?? '')) ?: (string) ($u['email'] ?? '?');
            $out[$id] = explode(' ', $name)[0];
        }

        return $out;
    }

    /** @param array<int, string> $people the people on a shared programme [id => first name], for the "who is this day for" chips; $me = the viewer */
    public static function build(array $program, array $days, ?DateTimeImmutable $now = null, string $tz = 'Europe/Copenhagen', array $people = [], ?int $me = null): array
    {
        $now   = $now ?? new DateTimeImmutable('now', new DateTimeZone($tz));
        $weeks = [];
        foreach ($days as $d) {
            $key = $d['year'] . '-' . $d['week'];
            $weeks[$key] ??= ['week' => $d['week'], 'year' => $d['year'], 'days' => []];
            $weeks[$key]['days'][] = [
                'weekday' => $d['weekday'], 'name' => self::NAMES[$d['weekday']] ?? '', 'title' => $d['title'], 'note' => $d['note'], 'audience' => $d['audience'] ?? null,
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
                'shared' => $people !== [], 'people' => array_map(static fn (int $id, string $first): array => ['id' => $id, 'first' => $first], array_keys($people), array_values($people)), 'me' => $me,
            ],
            'weeks'   => array_values($weeks),
            'today'   => ['week' => $thisWeek, 'weekday' => (int) $now->format('N')],
        ];
    }
}
