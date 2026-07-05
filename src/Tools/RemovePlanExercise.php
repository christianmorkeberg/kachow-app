<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: remove an exercise from a day's workout plan.
 */
final class RemovePlanExercise implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'remove_plan_exercise';
    }

    public function description(): string
    {
        return "Removes an exercise from a day's workout plan. Match the exercise name from the "
            . 'plan. Date is YYYY-MM-DD; omit for today.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'The planned exercise to remove.'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $exercise = trim((string) ($arguments['exercise'] ?? ''));
        if ($exercise === '') {
            return ['error' => 'Which exercise should I remove?'];
        }
        $date   = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $planId = $this->plans->planIdForDate($userId, $date);
        if ($planId === null) {
            return ['error' => 'There is no workout planned for ' . $date . '.'];
        }

        $removed = false;
        foreach ($this->plans->itemsForPlan($planId) as $row) {
            if (strcasecmp((string) $row['exercise'], $exercise) === 0) {
                $removed = $this->plans->removeItem($userId, (int) $row['id']);
                break;
            }
        }
        if (!$removed) {
            return ['error' => "\"{$exercise}\" isn't in the plan for {$date}."];
        }

        return ['removed' => $exercise, 'date' => $date, '_render' => $this->plans->cardForDate($userId, $date)];
    }
}
