<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: tick a planned exercise as done. If it has sets/reps targets, this also
 * logs those sets to workout history (once).
 */
final class CheckOffExercise implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'check_off_exercise';
    }

    public function description(): string
    {
        return 'Marks a planned exercise as done for a day (e.g. "done with squats"). If the '
            . 'exercise has set/rep targets, it is also logged to the workout history automatically. '
            . 'Match the exercise name from the plan. Date is YYYY-MM-DD; omit for today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'The planned exercise to tick off.'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        return WorkoutPlanItemHelper::setDone($this->plans, $userId, $arguments, true);
    }
}
