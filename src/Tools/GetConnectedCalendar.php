<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;
use App\Data\Connections;

/**
 * Tool: read a connected person's calendar events — only if they share their
 * calendar with you. Reads via their own Google token (they must have connected
 * their calendar).
 */
final class GetConnectedCalendar implements Tool
{
    public function __construct(
        private Connections $connections,
        private Calendar $calendar,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_calendar';
    }

    public function description(): string
    {
        return 'Gets calendar events of a person you are connected with, but only if they share their '
            . 'calendar with you. Identify them by email or name. Provide the range as RFC3339 UTC '
            . 'timestamps, e.g. "2026-07-10T00:00:00Z". Useful for "is Alex free on Saturday?".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
                'from'   => ['type' => 'string', 'description' => 'Start of range (inclusive), RFC3339.'],
                'to'     => ['type' => 'string', 'description' => 'End of range (exclusive), RFC3339.'],
            ],
            'required' => ['person', 'from', 'to'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'calendar');
        if (isset($access['error'])) {
            return $access;
        }

        $from = (string) ($arguments['from'] ?? '');
        $to   = (string) ($arguments['to'] ?? '');
        if ($from === '' || $to === '') {
            return ['error' => 'Both "from" and "to" timestamps are required.'];
        }

        $events = $this->calendar->getEvents($access['owner_id'], $from, $to);

        return ['person' => $access['person'], 'count' => count($events), 'events' => $events];
    }
}
