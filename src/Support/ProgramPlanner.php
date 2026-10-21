<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\TrainingPrograms;
use App\Data\WorkoutPlans;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns what a training programme prescribes for a date into that date's workout plan — the plan card the user ticks off. It happens
 * when the day (or its week) is asked for, only for today and later (history is never invented), never touches a plan that already has
 * exercises, and is harmless to repeat (WorkoutPlans::addOrMergeItem never doubles an exercise).
 */
final class ProgramPlanner
{
    public function __construct(private TrainingPrograms $programs, private WorkoutPlans $plans, private string $tz = 'Europe/Copenhagen')
    {
    }

    /**
     * @return array{label:string, items:int}|null what was put into the plan, or null when nothing was (no programme day, a past date, or the day already has a plan)
     */
    public function ensureDay(int $userId, string $date, ?string $today = null): ?array
    {
        $today = $today ?? (new DateTimeImmutable('now', new DateTimeZone($this->tz)))->format('Y-m-d');
        if ($date < $today) {
            return null;
        }
        $day = $this->programs->dayFor($userId, $date);
        if ($day === null || $day['items'] === []) {
            return null;
        }
        $existing = $this->plans->planIdForDate($userId, $date);
        if ($existing !== null && $this->plans->itemsForPlan($existing) !== []) {
            return null;
        }
        $planId = $this->plans->ensurePlanForDate($userId, $date, $day['title'] ?? $day['label']);
        foreach ($day['items'] as $i) {
            $this->plans->addOrMergeItem($planId, $i['exercise'], $i['sets'], $i['reps'], $i['weight'], $i['note']);
        }

        return ['label' => $day['label'], 'items' => count($day['items'])];
    }

    /**
     * The same for every day of the week containing $refDate (Mon–Sun).
     *
     * @return list<array{date:string, label:string, items:int}>
     */
    public function ensureWeek(int $userId, string $refDate, ?string $today = null): array
    {
        $monday = (new DateTimeImmutable($refDate))->modify('monday this week');
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->modify("+{$i} days")->format('Y-m-d');
            $r = $this->ensureDay($userId, $d, $today);
            if ($r !== null) {
                $out[] = ['date' => $d] + $r;
            }
        }

        return $out;
    }
}
