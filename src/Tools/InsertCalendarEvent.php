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
            . 'add, or book something. For a TIMED event, provide start and end as RFC3339 timestamps '
            . '(e.g. "2026-07-10T14:00:00Z"); if the user gives a local wall-clock time, pass that local '
            . 'time and set time_zone to their IANA zone (e.g. "Europe/Copenhagen") — do not silently '
            . 'assume UTC. If only a start time is given, pick a sensible duration (e.g. one hour). '
            . 'For an ALL-DAY event (the user says "all day", "hele dagen", a whole day, or a multi-day '
            . 'trip with no times), set all_day=true and give start and end as plain dates '
            . '("2026-08-02"); end is the LAST day (inclusive) — for a single all-day event make end the '
            . 'SAME date as start. NEVER fake an all-day event with 00:00 times (that drifts by timezone '
            . 'and spills into the next day). By default the event goes on the primary calendar; to add '
            . 'it to a specific calendar (e.g. a shared one), first call list_calendars for its id and '
            . 'pass calendar_id.';
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
                    'description' => 'Start — RFC3339 timestamp for a timed event ("2026-07-10T14:00:00Z"), '
                        . 'or a date ("2026-08-02") when all_day is true.',
                ],
                'end' => [
                    'type'        => 'string',
                    'description' => 'End — RFC3339 timestamp for a timed event. When all_day is true, a '
                        . 'date and the LAST day INCLUSIVE (same as start for a single all-day event).',
                ],
                'all_day' => [
                    'type'        => 'boolean',
                    'description' => 'True for an all-day event (start/end are dates, not times). Default false.',
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
                'calendar_id' => [
                    'type'        => 'string',
                    'description' => 'Optional id of the calendar to add the event to (from '
                        . 'list_calendars). Omit to use the primary calendar.',
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
            isset($arguments['calendar_id']) && $arguments['calendar_id'] !== '' ? (string) $arguments['calendar_id'] : 'primary',
            !empty($arguments['all_day']),
        );

        return [
            'created' => true,
            'event'   => $event,
        ];
    }
}
