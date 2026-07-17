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
        return 'Reports time spent at work from clock in/out events, for a SINGLE day or right-now '
            . 'status, WITH the individual clock in/out sessions. Use for "how long have I worked today", '
            . '"when did I arrive", "am I still clocked in", the sessions on a specific date, or this '
            . "week's sessions with their times. For an overview of hours ACROSS days — totals per "
            . 'day/week/month, a whole week/month, or "how have I worked this week" — prefer '
            . 'get_work_summary, which draws a bar chart. The user may have more than one workplace; pass '
            . '"place" to limit to one, or omit for all (breaks time down per workplace). Summarise '
            . 'briefly rather than listing every session. If it reports needs_fix, tell the user they '
            . 'have a session with no clock-out and ask when they left so it can be corrected with '
            . 'log_work_event.';
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
                'place' => [
                    'type'        => 'string',
                    'description' => 'Limit to one workplace by its label (e.g. "Office"). Omit for all.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $scope = (string) ($arguments['scope'] ?? 'today');
        $date  = isset($arguments['date']) ? (string) $arguments['date'] : null;
        $place = isset($arguments['place']) ? (string) $arguments['place'] : null;

        $summary = $this->events->summary($userId, $scope, $date, $place);

        // The card carries the detail; give the model the numbers to talk about.
        return [
            'range'         => $summary['range_label'],
            'total'         => $summary['total_label'],
            'total_minutes' => $summary['total_minutes'],
            'clocked_in'    => $summary['ongoing'],
            'session_count' => count($summary['sessions']),
            'by_place'      => $summary['places'],
            'sessions'      => $summary['sessions'],
            'needs_fix'     => $summary['needs_fix'],
            '_render'       => $summary['card'],
        ];
    }
}
