<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Tool: show the whole week's workout plan and what remains.
 */
final class GetWeekPlan implements Tool
{
    public function __construct(private WorkoutPlans $plans)
    {
    }

    public function name(): string
    {
        return 'get_week_plan';
    }

    public function description(): string
    {
        return "Shows the planned workouts for a whole week (Monday–Sunday) and how many exercises "
            . 'still remain. Use for "what am I training this week?" / "what\'s left this week?". Pass '
            . 'any date in the target week as YYYY-MM-DD; omit for the current week.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'date' => ['type' => 'string', 'description' => 'Any date in the week (YYYY-MM-DD). Omit for this week.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $card = $this->plans->cardForWeek($userId, $date);

        return [
            'days_planned' => count($card['days']),
            'remaining'    => $card['remaining'],
            '_render'      => $card,
        ];
    }
}
