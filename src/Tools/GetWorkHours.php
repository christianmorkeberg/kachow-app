<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: how long the user has worked (from geofence/manual clock in/out events),
 * for today, yesterday, this/last week, this/last month, a specific date, or a date range.
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
        return 'Reports time spent at work from clock in/out events — for a single day, this/last week, '
            . 'this/last month, or ANY date range (from/to) — WITH the individual clock in/out sessions '
            . 'and a per-workplace total. This is the authoritative source for hours worked (the work log '
            . 'does NOT track hours). Use for "how long have I worked today", "how much did I work this '
            . 'week / last week / this month", "when did I arrive", "am I still clocked in", the sessions '
            . 'on a specific date, or a period like "from 1 Sep until today at <place>" / "fra den 1. til i '
            . 'dag". For ANY multi-day period make ONE call with "from" and "to" (or the matching scope) '
            . 'and use its total — NEVER add up single-day calls, you will miss days. For an '
            . "overview of hours ACROSS days — totals per "
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
                    'enum'        => ['today', 'yesterday', 'week', 'lastweek', 'month', 'lastmonth'],
                    'description' => 'Which period to summarise: today, yesterday, this week, last week '
                        . '(lastweek), this calendar month, or last month (lastmonth). Defaults to today. '
                        . 'Ignored when date or from/to is given.',
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => 'A specific local date (YYYY-MM-DD) to summarise instead of scope.',
                ],
                'from' => [
                    'type'        => 'string',
                    'description' => 'Start of a date range (YYYY-MM-DD, inclusive). Use with "to".',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => 'End of a date range (YYYY-MM-DD, inclusive — "until today" means '
                        . 'today\'s date). Use with "from".',
                ],
                'place' => [
                    'type'        => 'string',
                    'description' => 'Limit to one workplace (prefix match: "Office" also covers "Office North"). Omit for all.',
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
        $from  = trim((string) ($arguments['from'] ?? ''));
        $to    = trim((string) ($arguments['to'] ?? ''));

        // A range: from/to (either end alone = that single day).
        $toDate = null;
        if ($from !== '' || $to !== '') {
            $date   = $from !== '' ? $from : $to;
            $toDate = $to !== '' ? $to : $from;
        }
        foreach ([$date, $toDate] as $d) {
            if ($d !== null && $d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return ['error' => 'Dates must be YYYY-MM-DD (got "' . $d . '").'];
            }
        }

        $summary = $this->events->summary($userId, $scope, $date, $place, $toDate);

        // The card carries the detail; give the model the numbers to talk about.
        $result = [
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
        if ($summary['sessions'] === [] && $place !== null && trim($place) !== '') {
            $result['known_places'] = $this->events->knownPlaces($userId);
            $result['hint'] = 'No sessions matched that workplace — check known_places and retry with a real label.';
        }

        return $result;
    }
}
