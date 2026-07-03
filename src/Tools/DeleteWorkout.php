<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Workouts;

/**
 * Tool: delete previously logged workout sets (to correct mistakes). Thin
 * wrapper over Data\Workouts delete methods.
 */
final class DeleteWorkout implements Tool
{
    public function __construct(private Workouts $workouts)
    {
    }

    public function name(): string
    {
        return 'delete_workout';
    }

    public function description(): string
    {
        return 'Deletes previously logged workout sets when the user made a mistake or wants to remove '
            . 'entries. Preferred: delete specific sets by id — first call get_workout_history to find '
            . "the relevant sets and their ids, then pass those ids here. Alternatively, delete all sets "
            . 'of one exercise, optionally within a date range (e.g. "delete the squats I logged today"). '
            . 'This permanently removes rows, so only delete what the user clearly asked to remove; if '
            . 'unsure which sets they mean, confirm first.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'ids' => [
                    'type'        => 'array',
                    'description' => 'Ids of specific workout sets to delete (from get_workout_history). '
                        . 'Preferred for precise deletion.',
                    'items'       => ['type' => 'integer'],
                ],
                'exercise' => [
                    'type'        => 'string',
                    'description' => 'Delete all sets of this exercise (used when ids are not given). '
                        . 'Combine with from/to to limit to a date range.',
                ],
                'from' => [
                    'type'        => 'string',
                    'description' => 'Optional start of range (inclusive), "YYYY-MM-DD" or '
                        . '"YYYY-MM-DD HH:MM:SS" UTC. Only used with exercise.',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => 'Optional end of range (inclusive), "YYYY-MM-DD" or '
                        . '"YYYY-MM-DD HH:MM:SS" UTC. Only used with exercise.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $ids      = $arguments['ids'] ?? [];
        $exercise = trim((string) ($arguments['exercise'] ?? ''));

        if (is_array($ids) && $ids !== []) {
            $deleted = $this->workouts->deleteByIds($userId, $ids);
            return ['deleted_sets' => $deleted];
        }

        if ($exercise !== '') {
            $from = isset($arguments['from']) && $arguments['from'] !== '' ? (string) $arguments['from'] : null;
            $to   = isset($arguments['to']) && $arguments['to'] !== '' ? (string) $arguments['to'] : null;
            $deleted = $this->workouts->deleteByExercise($userId, $exercise, $from, $to);
            return ['deleted_sets' => $deleted, 'exercise' => $exercise];
        }

        return ['error' => 'Specify which sets to delete: either "ids", or an "exercise" (optionally with a date range).'];
    }
}
