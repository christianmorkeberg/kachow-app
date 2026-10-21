<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;
use App\Support\ProgramCard;
use App\Support\ProgramInput;

/**
 * Tool: the user's training programmes — a list, or one programme shown as a card (every week, day and exercise) for checking after an import.
 */
final class GetTrainingProgram implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'get_training_program';
    }

    public function description(): string
    {
        return 'Shows the user\'s training programmes. Without `program`: lists them (name, status, weeks covered) and shows the active one as a card. With `program` '
            . '(name or id): shows that programme as a card — every week with its days and exercises — and with `week` returns that week\'s days in full. Use after '
            . 'saving a programme so the user can check it, and for "show my programme", "what is in week 44?", "mit træningsprogram". The card IS the display: '
            . 'do not re-list its exercises as text.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'program' => ['type' => 'string', 'description' => 'Programme name or id; omit to list them all.'],
                'week'    => ['type' => 'string', 'description' => 'One week of it, e.g. 44 — returned in full.'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $all = $this->programs->all($userId);
        if ($all === []) {
            return ['programs' => [], 'note' => 'They have no training programme saved. If they have one as a note (or you remember one), offer to save it with save_training_program.'];
        }
        $q = trim((string) ($arguments['program'] ?? ''));
        $p = $q === '' ? (array_values(array_filter($all, static fn (array $x): bool => $x['status'] === 'active'))[0] ?? $all[0]) : $this->programs->find($userId, $q);
        if ($p === null) {
            return ['error' => 'No programme matches "' . $q . '".', 'programs' => array_map(static fn (array $x): string => $x['name'], $all)];
        }
        $week = isset($arguments['week']) ? ProgramInput::week($arguments['week']) : null;
        $days = $this->programs->days($userId, $p['id']);
        $out  = [
            'programs'  => array_map(static fn (array $x): array => ['id' => $x['id'], 'name' => $x['name'], 'status' => $x['status'], 'days' => $x['days'],
                'weeks' => $x['first_week'] !== null ? $x['first_week'] . '–' . $x['last_week'] : null, 'week_mode' => $x['week_mode']], $all),
            'shown'     => $p['name'],
            '_render'   => ProgramCard::build($p, $days),
            'note'      => 'The card shows the programme — do NOT re-list it. Ask the user to check it; changes are made by sending the changed day again (add_program_days).',
        ];
        if ($week !== null) {
            $out['week'] = $week;
            $out['days'] = array_values(array_map(static fn (array $d): array => [
                'weekday' => $d['weekday'], 'title' => $d['title'], 'exercises' => array_map([ProgramInput::class, 'itemLabel'], $d['items']),
            ], array_filter($days, static fn (array $d): bool => $d['week'] === $week)));
        }

        return $out;
    }
}
