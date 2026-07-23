<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ExerciseAliases;
use App\Data\Workouts;
use App\Support\OneRepMax;

/**
 * Tool: retrieve workout history. Thin wrapper over Data\Workouts::getHistory.
 */
final class GetWorkoutHistory implements Tool
{
    public function __construct(
        private Workouts $workouts,
        private ExerciseAliases $aliases,
    ) {
    }

    public function name(): string
    {
        return 'get_workout_history';
    }

    public function description(): string
    {
        return "Retrieves the user's logged workout sets, newest first, optionally filtered by "
            . 'exercise and/or a date range. Use when the user asks about past performance, progress, '
            . 'personal records, or what they lifted. Each row is a single set. The result also includes '
            . '`records` (computed per exercise): `heaviest` (top set), `tested_1rm` (their best ACTUAL '
            . '1-rep max, or null if never tested at 1 rep), and `est_1rm` (best Epley estimate with its '
            . 'source set). For 1RM / PR / percentage questions, USE these computed records verbatim — do '
            . 'NOT re-derive maxes or 1RMs from the raw sets yourself. "Real 1RM" = tested_1rm; '
            . '"estimated 1RM" = est_1rm.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => [
                    'type'        => 'string',
                    'description' => 'Filter to a single exercise (exact name). Omit for all exercises.',
                ],
                'from' => [
                    'type'        => 'string',
                    'description' => 'Start of range (inclusive), "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" UTC.',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => 'End of range (inclusive), "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS" UTC.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of sets to return.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $exercise = isset($arguments['exercise']) && $arguments['exercise'] !== ''
            ? $this->aliases->resolve($userId, (string) $arguments['exercise']) : null;
        $from  = isset($arguments['from']) && $arguments['from'] !== '' ? (string) $arguments['from'] : null;
        $to    = isset($arguments['to']) && $arguments['to'] !== '' ? (string) $arguments['to'] : null;
        $limit = isset($arguments['limit']) && $arguments['limit'] !== '' ? (int) $arguments['limit'] : null;

        $sets = $this->workouts->getHistory($userId, $exercise, $from, $to, $limit);

        return [
            'count'   => count($sets),
            'records' => OneRepMax::records($sets),
            'sets'    => $sets,
        ];
    }
}
