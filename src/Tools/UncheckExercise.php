<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: un-tick a planned exercise (mark as not done). Does not remove any sets
 * already logged to history.
 */
final class UncheckExercise implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'uncheck_exercise';
    }

    public function description(): string
    {
        return 'Marks a planned exercise as NOT done again (undo a tick). Note: this does not remove '
            . 'any sets already logged for it. Match the exercise name from the plan. Date is '
            . 'YYYY-MM-DD; omit for today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'The planned exercise to un-tick.'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        return WorkoutPlanItemHelper::setDone($this->plans, $userId, $arguments, false);
    }
}
