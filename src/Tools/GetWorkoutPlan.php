<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: show a day's workout plan (its exercises and which are done).
 */
final class GetWorkoutPlan implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'get_workout_plan';
    }

    public function description(): string
    {
        return "Shows the planned workout for a day — its exercises and which are ticked off. "
            . 'Use for "what am I training today?" or to see what remains. Date is YYYY-MM-DD; omit '
            . 'for today. For the whole week use get_week_plan.';
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
        $date = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $card = $this->plans->cardForDate($userId, $date);
        $day  = $card['days'][0];

        // Only a summary goes to the model (the interactive card carries the details,
        // so the model must not re-list exercises as text).
        return [
            'date'      => $date,
            'has_plan'  => $day['plan_id'] !== null,
            'item_count' => count($day['items']),
            'remaining' => count(array_filter($day['items'], static fn (array $i): bool => !$i['done'])),
            '_render'   => $card,
        ];
    }
}
