<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\WorkoutPlans;

/**
 * Tool: read a connected person's PLANNED workouts (a day, or the whole week) — the plan counterpart
 * of get_connected_workouts (which is what they DID). Gated by the same `workouts` scope: a plan is part
 * of sharing your workouts, so anyone who can see someone's history can see what they have lined up.
 * Read-only by construction: it only calls the plan card builders, and the card is marked readonly so the
 * page offers no tick boxes (ticking is also user-scoped server-side).
 */
final class GetConnectedWorkoutPlan implements Tool
{
    public function __construct(
        private Connections $connections,
        private WorkoutPlans $plans,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_workout_plan';
    }

    public function description(): string
    {
        return 'Shows the PLANNED workouts of a person you are connected with (what they intend to train, '
            . 'and which exercises they have ticked off) for a day or a whole week, but only if they share '
            . 'their workouts with you. Identify them by email or name (see list_connections). Use for "what '
            . 'is Alex training today?", "has Alex trained yet?", "what is on Alex\'s plan this week?", Danish '
            . '"hvad skal Alex træne i dag?", "kan du se Alex\' træningsplan?". Read-only: you cannot tick '
            . 'off, add to or change THEIR plan. For what they have actually DONE use get_connected_workouts.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'date'   => ['type' => 'string', 'description' => 'Date YYYY-MM-DD (for period "week": any date in that week). Omit for today / this week.'],
                'period' => ['type' => 'string', 'enum' => ['day', 'week'], 'description' => '"day" (default) or "week" (Monday–Sunday).'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'workouts');
        if (isset($access['error'])) {
            return $access;
        }

        $ownerId = (int) $access['owner_id'];
        $date    = CreateWorkoutPlan::resolveDate($arguments['date'] ?? null);
        $week    = ($arguments['period'] ?? 'day') === 'week';

        $card = ConnectionAccess::attribute(
            $week ? $this->plans->cardForWeek($ownerId, $date) : $this->plans->cardForDate($ownerId, $date),
            $access['person']
        );

        $days = array_map(static fn (array $d): array => [
            'weekday'   => $d['weekday'] ?? null,
            'date'      => $d['date'] ?? null,
            'title'     => $d['plan_title'] ?? null,
            'exercises' => array_map(
                static fn (array $i): array => ['name' => (string) $i['label'], 'done' => (bool) $i['done']],
                $d['items'] ?? []
            ),
        ], array_values(array_filter($card['days'], static fn (array $d): bool => $d['plan_id'] !== null)));

        $remaining = 0;
        foreach ($days as $d) {
            foreach ($d['exercises'] as $e) {
                $remaining += $e['done'] ? 0 : 1;
            }
        }

        return [
            'person'    => $access['person'],
            'period'    => $week ? 'week' : 'day',
            'date'      => $date,
            'has_plan'  => $days !== [],
            'remaining' => $remaining,
            'days'      => $days,
            'note'      => 'This is THEIR plan (read-only) — attribute it to them, never mix it with your own. The '
                . 'card shows it; use the exercise names to answer the question but do NOT just re-list them as text. '
                . ($days === [] ? 'They have no plan for ' . ($week ? 'that week' : 'that day') . '.' : ''),
            '_render'   => $card,
        ];
    }
}
