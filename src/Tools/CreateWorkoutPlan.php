<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: create/add to a planned workout session for a date, with a list of
 * exercises (optionally with sets/reps/weight targets). Call once per day when
 * building a week's program.
 */
final class CreateWorkoutPlan implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'create_workout_plan';
    }

    public function description(): string
    {
        return 'Plans a workout session: adds one or more exercises to the plan for a date '
            . '(creating that day\'s plan if needed). Use for "today I\'ll do X, then Y" and for '
            . 'building a week\'s program (call once per training day). Give targets (sets/reps/'
            . 'weight) when known so they can be logged on tick; use note for things like "5k run". '
            . 'Dates are YYYY-MM-DD; omit for today. This is PLANNING, separate from logging done sets.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date'  => ['type' => 'string', 'description' => 'Session date YYYY-MM-DD. Omit for today.'],
                'title' => ['type' => 'string', 'description' => 'Optional session title, e.g. "Legs" or "Push".'],
                'exercises' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'exercise' => ['type' => 'string', 'description' => 'Exercise name.'],
                            'sets'     => ['type' => 'integer', 'description' => 'Target number of sets.'],
                            'reps'     => ['type' => 'integer', 'description' => 'Target reps per set.'],
                            'weight'   => ['type' => 'number', 'description' => 'Target weight in kg.'],
                            'note'     => ['type' => 'string', 'description' => 'Free note, e.g. "5k easy".'],
                        ],
                        'required' => ['exercise'],
                    ],
                    'description' => 'Exercises to add to the session.',
                ],
            ],
            'required' => ['exercises'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date  = self::resolveDate($arguments['date'] ?? null);
        $title = trim((string) ($arguments['title'] ?? ''));
        $exs   = is_array($arguments['exercises'] ?? null) ? $arguments['exercises'] : [];

        $planId = $this->plans->ensurePlanForDate($userId, $date, $title !== '' ? $title : null);

        $added = [];
        foreach ($exs as $ex) {
            $name = trim((string) ($ex['exercise'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->plans->addItem(
                $planId,
                $name,
                isset($ex['sets']) && $ex['sets'] !== '' ? (int) $ex['sets'] : null,
                isset($ex['reps']) && $ex['reps'] !== '' ? (int) $ex['reps'] : null,
                isset($ex['weight']) && $ex['weight'] !== '' ? (float) $ex['weight'] : null,
                isset($ex['note']) ? trim((string) $ex['note']) : null
            );
            $added[] = $name;
        }

        if ($added === []) {
            return ['error' => 'Give at least one exercise to plan.'];
        }

        return [
            'planned_for' => $date,
            'added'       => $added,
            '_render'     => $this->plans->cardForDate($userId, $date),
        ];
    }

    public static function resolveDate(mixed $raw): string
    {
        $s = is_string($raw) ? trim($raw) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1 ? $s : date('Y-m-d');
    }
}
