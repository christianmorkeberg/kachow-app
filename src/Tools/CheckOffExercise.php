<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkoutPlans;
use App\Support\ProgramPlanner;

/**
 * Tool: tick a planned exercise as done. If it has sets/reps targets, this also
 * logs those sets to workout history (once).
 */
final class CheckOffExercise implements Tool
{
    public function __construct(private WorkoutPlans $plans, private ?ProgramPlanner $planner = null)
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
            . 'Match the exercise name from the plan. Date is YYYY-MM-DD; omit for today. For "today\'s workout is done" / '
            . '"did the whole session" pass all=true instead of ticking exercises one by one. A day of the saved training programme '
            . 'is put into the plan first if needed, also for a past day.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'exercise' => ['type' => 'string', 'description' => 'The planned exercise to tick off. Not needed with all=true.'],
                'all'      => ['type' => 'boolean', 'description' => 'true = tick off every exercise of that day\'s plan in one go.'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $date = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        if ($this->planner !== null && $this->plans->planIdForDate($userId, $date) === null) {
            try {
                $this->planner->ensureDay($userId, $date, null, true);
            } catch (\Throwable) {
                // no programme day: the normal "nothing planned" answer below
            }
        }
        if (!empty($arguments['all'])) {
            $planId = $this->plans->planIdForDate($userId, $date);
            if ($planId === null) {
                return ['error' => 'There is no workout planned for ' . $date . '.'];
            }
            $done = [];
            $logged = 0;
            foreach ($this->plans->itemsForPlan($planId) as $row) {
                if ((int) ($row['done'] ?? 0) === 1) {
                    continue;
                }
                $res = $this->plans->check($userId, (int) $row['id'], true);
                if (!isset($res['error'])) {
                    $done[] = (string) $row['exercise'];
                    $logged += $res['logged'] ? 1 : 0;
                }
            }

            return [
                'checked_off' => $done, 'also_logged' => $logged,
                'note'        => 'Exercises without set/rep targets are ticked but not logged — ask for weights only if the user wants them recorded.',
                '_render'     => $this->plans->cardForDate($userId, $date),
            ];
        }

        return WorkoutPlanItemHelper::setDone($this->plans, $userId, $arguments, true);
    }
}
