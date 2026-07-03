<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: create a calendar event. Thin wrapper over Data\Calendar::insertEvent.
 */
final class InsertCalendarEvent implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'insert_calendar_event';
    }

    public function description(): string
    {
        return "Creates an event on the user's Google Calendar. Use when the user asks to schedule, "
            . 'add, or book something. Provide start and end as RFC3339 timestamps, e.g. '
            . '"2026-07-10T14:00:00Z". If the user gives only a start time, choose a sensible '
            . 'duration (e.g. one hour) for the end.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'summary' => [
                    'type'        => 'string',
                    'description' => 'Title of the event, e.g. "Dentist appointment".',
                ],
                'start' => [
                    'type'        => 'string',
                    'description' => 'Start time, RFC3339, e.g. "2026-07-10T14:00:00Z".',
                ],
                'end' => [
                    'type'        => 'string',
                    'description' => 'End time, RFC3339, e.g. "2026-07-10T15:00:00Z".',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => 'Optional longer description / notes for the event.',
                ],
                'location' => [
                    'type'        => 'string',
                    'description' => 'Optional location.',
                ],
                'time_zone' => [
                    'type'        => 'string',
                    'description' => 'Optional IANA time zone for the times (default "UTC").',
                ],
            ],
            'required' => ['summary', 'start', 'end'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $summary = trim((string) ($arguments['summary'] ?? ''));
        $start   = (string) ($arguments['start'] ?? '');
        $end     = (string) ($arguments['end'] ?? '');
        if ($summary === '' || $start === '' || $end === '') {
            return ['error' => 'summary, start and end are all required.'];
        }

        $event = $this->calendar->insertEvent(
            $userId,
            $summary,
            $start,
            $end,
            isset($arguments['description']) && $arguments['description'] !== '' ? (string) $arguments['description'] : null,
            isset($arguments['location']) && $arguments['location'] !== '' ? (string) $arguments['location'] : null,
            isset($arguments['time_zone']) && $arguments['time_zone'] !== '' ? (string) $arguments['time_zone'] : 'UTC',
        );

        return [
            'created' => true,
            'event'   => $event,
        ];
    }
}
