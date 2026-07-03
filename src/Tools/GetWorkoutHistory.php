<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Workouts;

/**
 * Tool: retrieve workout history. Thin wrapper over Data\Workouts::getHistory.
 */
final class GetWorkoutHistory implements Tool
{
    public function __construct(private Workouts $workouts)
    {
    }

    public function name(): string
    {
        return 'get_workout_history';
    }

    public function description(): string
    {
        return "Retrieves the user's logged workout sets, newest first, optionally filtered by "
            . 'exercise and/or a date range. Use when the user asks about past performance, progress, '
            . 'personal records, or what they lifted. Each row is a single set.';
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
            ? (string) $arguments['exercise'] : null;
        $from  = isset($arguments['from']) && $arguments['from'] !== '' ? (string) $arguments['from'] : null;
        $to    = isset($arguments['to']) && $arguments['to'] !== '' ? (string) $arguments['to'] : null;
        $limit = isset($arguments['limit']) && $arguments['limit'] !== '' ? (int) $arguments['limit'] : null;

        $sets = $this->workouts->getHistory($userId, $exercise, $from, $to, $limit);

        return [
            'count' => count($sets),
            'sets'  => $sets,
        ];
    }
}
