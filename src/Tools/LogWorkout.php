<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ExerciseAliases;
use App\Data\Workouts;

/**
 * Tool: log completed workout sets. Thin wrapper over Data\Workouts::logSets, with
 * exercise-name canonicalisation so variants of the same lift don't fragment history.
 */
final class LogWorkout implements Tool
{
    public function __construct(
        private Workouts $workouts,
        private ExerciseAliases $aliases,
    ) {
    }

    public function name(): string
    {
        return 'log_workout';
    }

    public function description(): string
    {
        return 'Logs one or more completed sets of a strength/gym exercise for the user. '
            . 'IMPORTANT: one entry in "sets" = one performed set, so "Squats 80kg 5x5" is five '
            . 'entries of 5 reps at 80kg. Use this whenever the user reports having done an exercise. '
            . 'Weight is in kilograms and may be omitted for bodyweight exercises.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => [
                    'type'        => 'string',
                    'description' => 'Name of the exercise, e.g. "Squats", "Bench Press", "Pull-ups".',
                ],
                'sets' => [
                    'type'        => 'array',
                    'description' => 'One object per set performed. Repeat identical objects for '
                        . 'repeated identical sets (5x5 = five entries).',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'weight' => [
                                'type'        => 'number',
                                'description' => 'Weight in kg. Omit for bodyweight exercises.',
                            ],
                            'reps' => [
                                'type'        => 'integer',
                                'description' => 'Repetitions performed in this set.',
                            ],
                            'notes' => [
                                'type'        => 'string',
                                'description' => 'Optional per-set note, e.g. "felt strong", "new PR".',
                            ],
                        ],
                    ],
                ],
                'logged_at' => [
                    'type'        => 'string',
                    'description' => 'When the workout was done, as "YYYY-MM-DD HH:MM:SS" in UTC. '
                        . 'Defaults to now if omitted.',
                ],
            ],
            'required' => ['exercise', 'sets'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $exercise = trim((string) ($arguments['exercise'] ?? ''));
        $rawSets  = $arguments['sets'] ?? [];
        $loggedAt = isset($arguments['logged_at']) && $arguments['logged_at'] !== ''
            ? (string) $arguments['logged_at']
            : null;

        if ($exercise === '' || !is_array($rawSets) || $rawSets === []) {
            return ['error' => 'Provide an exercise name and at least one set.'];
        }

        // Canonicalise the name so a registered variant lands on the user's chosen name.
        $exercise = $this->aliases->resolve($userId, $exercise);

        $sets = [];
        foreach ($rawSets as $set) {
            $set = (array) $set;
            $sets[] = [
                'weight' => isset($set['weight']) && $set['weight'] !== '' ? (float) $set['weight'] : null,
                'reps'   => isset($set['reps']) && $set['reps'] !== '' ? (int) $set['reps'] : null,
                'notes'  => isset($set['notes']) && $set['notes'] !== '' ? (string) $set['notes'] : null,
            ];
        }

        $ids = $this->workouts->logSets($userId, $exercise, $sets, $loggedAt);

        return [
            'logged_sets' => count($ids),
            'exercise'    => $exercise,
        ];
    }
}
