<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\WorkEvents;

/**
 * Tool: read a connected person's clocked work hours — only if they share `work_hours` with the acting
 * user. Answers "is Alex still at work?" and "how much did Alex work this week?". Read-only: nothing here
 * can clock them in/out or correct their log, and the card is marked readonly so the page neither
 * auto-refreshes it from the VIEWER's own clock nor offers to fix a forgotten clock-out.
 */
final class GetConnectedWorkHours implements Tool
{
    public function __construct(
        private Connections $connections,
        private WorkEvents $events,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_work_hours';
    }

    public function description(): string
    {
        return 'Reports the clocked work hours of a person you are connected with — whether they are at '
            . 'work right now, and their hours for a day, week, month or any date range, with sessions and '
            . 'a per-workplace total — but only if they share their work hours with you. Identify them by '
            . 'email or name (see list_connections). Use for "is Alex still at work?", "when did Alex '
            . 'start today?", "how many hours has Alex worked this week?", Danish "er Alex på arbejde?", '
            . '"hvornår har Alex fri?". Same period options as get_work_hours: for any multi-day period make '
            . 'ONE call with from/to and use its total. Read-only: you cannot clock them in or out or fix '
            . 'their log. Attribute everything to THEM, never mix it with your own hours.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'scope'  => [
                    'type'        => 'string',
                    'enum'        => ['today', 'yesterday', 'week', 'lastweek', 'month', 'lastmonth'],
                    'description' => 'Period to summarise. Defaults to today. Ignored when date or from/to is given.',
                ],
                'date'   => ['type' => 'string', 'description' => 'A specific local date (YYYY-MM-DD) instead of scope.'],
                'from'   => ['type' => 'string', 'description' => 'Start of a date range (YYYY-MM-DD, inclusive). Use with "to".'],
                'to'     => ['type' => 'string', 'description' => 'End of a date range (YYYY-MM-DD, inclusive). Use with "from".'],
                'place'  => ['type' => 'string', 'description' => 'Limit to one workplace (prefix match). Omit for all.'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'work_hours');
        if (isset($access['error'])) {
            return $access;
        }

        $scope = (string) ($arguments['scope'] ?? 'today');
        $date  = isset($arguments['date']) ? (string) $arguments['date'] : null;
        $place = isset($arguments['place']) ? (string) $arguments['place'] : null;
        $from  = trim((string) ($arguments['from'] ?? ''));
        $to    = trim((string) ($arguments['to'] ?? ''));

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

        $summary = $this->events->summary((int) $access['owner_id'], $scope, $date, $place, $toDate);

        // A forgotten clock-out is theirs to fix — don't show the viewer a "tell me when you left" prompt.
        $card = $summary['card'];
        $card['needs_fix'] = [];

        $result = [
            'person'        => $access['person'],
            'range'         => $summary['range_label'],
            'total'         => $summary['total_label'],
            'total_minutes' => $summary['total_minutes'],
            'clocked_in'    => $summary['ongoing'],
            'session_count' => count($summary['sessions']),
            'by_place'      => $summary['places'],
            'sessions'      => $summary['sessions'],
            '_render'       => ConnectionAccess::attribute($card, $access['person']),
        ];
        if ($summary['needs_fix'] !== []) {
            $result['note'] = 'They have ' . count($summary['needs_fix']) . ' session(s) without a clock-out, which '
                . 'are not counted. Only they can correct that — do not offer to.';
        }

        return $result;
    }
}
