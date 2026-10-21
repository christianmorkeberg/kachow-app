<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\TrainingPrograms;

/**
 * Tool: change a training programme itself — its name, whether it is active (it only fills plans while active), how its weeks count.
 */
final class UpdateTrainingProgram implements Tool
{
    public function __construct(private TrainingPrograms $programs)
    {
    }

    public function name(): string
    {
        return 'update_training_program';
    }

    public function description(): string
    {
        return 'Changes a training programme: rename it, set status "active" or "archived" (only active programmes fill the daily plan — archive an old one when '
            . 'the user starts a new one), or change how its weeks count (week_mode calendar / relative, start_date, repeat_weeks). To change an exercise or a '
            . 'day, send that day again with add_program_days; to drop days use remove_program_days.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'program'      => ['type' => 'string', 'description' => 'Programme name or id.'],
                'name'         => ['type' => 'string'],
                'status'       => ['type' => 'string', 'enum' => ['active', 'archived']],
                'week_mode'    => ['type' => 'string', 'enum' => ['calendar', 'relative']],
                'start_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, any date in week 1 (relative programmes).'],
                'repeat_weeks' => ['type' => 'integer', 'description' => 'Repeats every N weeks; 0 = does not repeat.'],
            ],
            'required' => ['program'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $p = $this->programs->find($userId, (string) ($arguments['program'] ?? ''));
        if ($p === null) {
            return ['error' => 'No programme matches "' . ($arguments['program'] ?? '') . '".'];
        }
        $f = array_intersect_key($arguments, array_flip(['name', 'status', 'week_mode', 'start_date', 'repeat_weeks']));
        $this->programs->update($userId, $p['id'], $f);
        $now = $this->programs->get($userId, $p['id']);

        return ['updated' => true, 'programme' => ['id' => $now['id'], 'name' => $now['name'], 'status' => $now['status'], 'week_mode' => $now['week_mode'], 'start_date' => $now['start_date'], 'repeat_weeks' => $now['repeat_weeks']]];
    }
}
