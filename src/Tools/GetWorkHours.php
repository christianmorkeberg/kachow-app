<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: how long the user has worked (from geofence/manual clock in/out events),
 * for today, yesterday, this week, or a specific date.
 */
final class GetWorkHours implements Tool
{
    public function __construct(private WorkEvents $events)
    {
    }

    public function name(): string
    {
        return 'get_work_hours';
    }

    public function description(): string
    {
        return 'Reports how much time the user has spent at work, from their clock in/out events '
            . '(logged automatically when they arrive at / leave work, or added manually). Use for '
            . '"how long have I worked today / this week", "when did I arrive", "am I still clocked in". '
            . 'The app shows the result as a card, so summarise briefly rather than listing every '
            . 'session. If it reports needs_fix, tell the user they have a session with no clock-out '
            . 'and ask when they left so it can be corrected with log_work_event.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'scope' => [
                    'type'        => 'string',
                    'enum'        => ['today', 'yesterday', 'week'],
                    'description' => 'Which period to summarise. Defaults to today.',
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => 'A specific local date (YYYY-MM-DD) to summarise instead of scope.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $scope = (string) ($arguments['scope'] ?? 'today');
        $date  = isset($arguments['date']) ? (string) $arguments['date'] : null;

        $summary = $this->events->summary($userId, $scope, $date);

        // The card carries the detail; give the model the numbers to talk about.
        return [
            'range'         => $summary['range_label'],
            'total'         => $summary['total_label'],
            'total_minutes' => $summary['total_minutes'],
            'clocked_in'    => $summary['ongoing'],
            'session_count' => count($summary['sessions']),
            'sessions'      => $summary['sessions'],
            'needs_fix'     => $summary['needs_fix'],
            '_render'       => $summary['card'],
        ];
    }
}
