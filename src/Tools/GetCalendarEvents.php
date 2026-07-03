<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: read calendar events. Thin wrapper over Data\Calendar::getEvents.
 */
final class GetCalendarEvents implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'get_calendar_events';
    }

    public function description(): string
    {
        return "Gets events from all of the user's visible Google Calendars (their primary calendar "
            . 'plus any shared calendars) within a time range, merged and ordered by start time. Each '
            . 'event includes a "calendar" field naming which calendar it belongs to. Use for questions '
            . 'about their schedule, appointments, meetings, or availability. Provide the range as '
            . 'RFC3339 timestamps in UTC, e.g. "2026-07-10T00:00:00Z".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from' => [
                    'type'        => 'string',
                    'description' => 'Start of the range (inclusive), RFC3339, e.g. "2026-07-10T00:00:00Z".',
                ],
                'to' => [
                    'type'        => 'string',
                    'description' => 'End of the range (exclusive), RFC3339, e.g. "2026-07-11T00:00:00Z".',
                ],
            ],
            'required' => ['from', 'to'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $from = (string) ($arguments['from'] ?? '');
        $to   = (string) ($arguments['to'] ?? '');
        if ($from === '' || $to === '') {
            return ['error' => 'Both "from" and "to" timestamps are required.'];
        }

        $events = $this->calendar->getEvents($userId, $from, $to);

        return [
            'count'  => count($events),
            'events' => $events,
        ];
    }
}
