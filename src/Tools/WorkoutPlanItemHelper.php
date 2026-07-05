<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;

/**
 * Shared logic for the check/uncheck exercise tools: resolve the item by exercise
 * name within a day's plan, toggle it (which may log), and return a refreshed card.
 */
final class WorkoutPlanItemHelper
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function setDone(WorkoutPlans $plans, int $userId, array $arguments, bool $done): array
    {
        $exercise = trim((string) ($arguments['exercise'] ?? ''));
        if ($exercise === '') {
            return ['error' => 'Which exercise?'];
        }
        $date = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);

        $planId = $plans->planIdForDate($userId, $date);
        if ($planId === null) {
            return ['error' => 'There is no workout planned for ' . $date . '.'];
        }

        $itemId = null;
        foreach ($plans->itemsForPlan($planId) as $row) {
            if (strcasecmp((string) $row['exercise'], $exercise) === 0) {
                $itemId = (int) $row['id'];
                break;
            }
        }
        if ($itemId === null) {
            return ['error' => "\"{$exercise}\" isn't in the plan for {$date}."];
        }

        $res = $plans->check($userId, $itemId, $done);
        if (isset($res['error'])) {
            return $res;
        }

        return [
            $done ? 'checked_off' : 'unchecked' => $exercise,
            'also_logged'                       => $res['logged'],
            '_render'                           => $plans->cardForDate($userId, $date),
        ];
    }
}
