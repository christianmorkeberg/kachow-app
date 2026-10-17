<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: add a single exercise to an existing (or new) day's plan.
 */
final class AddPlanExercise implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'add_plan_exercise';
    }

    public function description(): string
    {
        return 'Adds one exercise to a day\'s workout plan (creating the day\'s plan if needed). '
            . 'Give sets/reps/weight when known. Date is YYYY-MM-DD; omit for today. For adding '
            . 'several at once, use create_workout_plan. An exercise already in the plan is not added twice.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'Exercise name.'],
                'sets'     => ['type' => 'integer', 'description' => 'Target sets.'],
                'reps'     => ['type' => 'integer', 'description' => 'Target reps per set.'],
                'weight'   => ['type' => 'number', 'description' => 'Target weight in kg.'],
                'note'     => ['type' => 'string', 'description' => 'Free note, e.g. "5k easy".'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['exercise'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $name = trim((string) ($arguments['exercise'] ?? ''));
        if ($name === '') {
            return ['error' => 'Which exercise should I add?'];
        }
        $date   = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $planId = $this->plans->ensurePlanForDate($userId, $date);

        $res = $this->plans->addOrMergeItem(
            $planId,
            $name,
            isset($arguments['sets']) && $arguments['sets'] !== '' ? (int) $arguments['sets'] : null,
            isset($arguments['reps']) && $arguments['reps'] !== '' ? (int) $arguments['reps'] : null,
            isset($arguments['weight']) && $arguments['weight'] !== '' ? (float) $arguments['weight'] : null,
            isset($arguments['note']) ? trim((string) $arguments['note']) : null
        );
        $card = $this->plans->cardForDate($userId, $date);

        return match ($res['result']) {
            'added'  => ['added' => $name, 'date' => $date, '_render' => $card],
            'filled' => ['filled_in' => $name, 'date' => $date, 'note' => 'It was already in the plan; its missing targets were filled in. Nothing was duplicated.', '_render' => $card],
            default  => ['already_planned' => $name, 'date' => $date, 'note' => 'It was already in the plan, so nothing was added. Tell the user.', '_render' => $card],
        };
    }
}
