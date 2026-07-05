<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: delete a whole day's workout plan and its items.
 */
final class DeleteWorkoutPlan implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'delete_workout_plan';
    }

    public function description(): string
    {
        return "Deletes an entire day's workout plan and all its exercises. Does not remove sets "
            . 'already logged to history. Date is YYYY-MM-DD; omit for today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date   = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $planId = $this->plans->planIdForDate($userId, $date);
        if ($planId === null) {
            return ['error' => 'There is no workout planned for ' . $date . '.'];
        }

        $this->plans->deletePlan($userId, $planId);

        return ['deleted_plan_for' => $date];
    }
}
