<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: move or edit an existing calendar event. Thin wrapper over Data\Calendar::updateEvent.
 */
final class UpdateCalendarEvent implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'update_calendar_event';
    }

    public function description(): string
    {
        return "Moves or edits an EXISTING event on the user's Google Calendar — use for \"move it to "
            . '3pm", "rename it to…", "it\'s actually at a different place", or a whole-day reschedule. '
            . 'First call get_calendar_events to find the event and its "id" (and "calendar_id" if not on '
            . 'the primary calendar); then pass those here with only the fields that change. To MOVE a '
            . 'timed event, pass BOTH start and end as RFC3339 timestamps (so the duration moves with it, '
            . 'not just the start) — passing start alone leaves the old end in place. Leave out any field '
            . 'that should stay the same. For an all-day event, give start/end as plain dates with end as '
            . 'the LAST day inclusive, same as insert_calendar_event. Do NOT delete-then-recreate an event '
            . 'to move it — this preserves its id and any other field you don\'t touch. attendees REPLACES '
            . 'the whole guest list (including one of the user\'s own other calendars invited to block '
            . 'time on it — see insert_calendar_event) — to add one more without dropping the rest, first '
            . 'read the event\'s current "attendees" (from get_calendar_events or this tool\'s own result) '
            . 'and include those emails too.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id' => [
                    'type'        => 'string',
                    'description' => 'The id of the event to update (from get_calendar_events).',
                ],
                'calendar_id' => [
                    'type'        => 'string',
                    'description' => 'The id of the calendar the event is on (the event\'s "calendar_id" '
                        . 'from get_calendar_events). Omit if it is on the primary calendar.',
                ],
                'summary' => [
                    'type'        => 'string',
                    'description' => 'New title, if it changes.',
                ],
                'start' => [
                    'type'        => 'string',
                    'description' => 'New start — RFC3339 timestamp for a timed event, or a date when '
                        . 'all_day is true. Give end too so the duration moves with it.',
                ],
                'end' => [
                    'type'        => 'string',
                    'description' => 'New end — RFC3339 timestamp, or a date (last day inclusive) when '
                        . 'all_day is true.',
                ],
                'all_day' => [
                    'type'        => 'boolean',
                    'description' => 'Set true to switch it to an all-day event, false to switch it to a '
                        . 'timed event. Omit to leave that as-is.',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => 'New longer description / notes, if it changes.',
                ],
                'location' => [
                    'type'        => 'string',
                    'description' => 'New location, if it changes.',
                ],
                'time_zone' => [
                    'type'        => 'string',
                    'description' => 'IANA time zone for new start/end times (e.g. "Europe/Copenhagen"). '
                        . 'Omit to keep the event\'s current zone.',
                ],
                'attendees' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'REPLACES the event\'s whole attendee list (include existing ones you '
                        . 'want kept, from the event\'s current "attendees"). Pass [] to clear it, or omit '
                        . 'entirely to leave attendees untouched.',
                ],
                'notify' => [
                    'type'        => 'boolean',
                    'description' => 'True to email the attendee-list change. Default false — leave it '
                        . 'false when attendees is just the user\'s own other calendar(s).',
                ],
            ],
            'required' => ['event_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $eventId = trim((string) ($arguments['event_id'] ?? ''));
        if ($eventId === '') {
            return ['error' => 'An event id is required (get it from get_calendar_events).'];
        }

        $str = static fn (string $k): ?string => isset($arguments[$k]) && $arguments[$k] !== '' ? (string) $arguments[$k] : null;

        $hasChange = $str('summary') !== null || $str('start') !== null || $str('end') !== null
            || $str('description') !== null || $str('location') !== null || array_key_exists('all_day', $arguments)
            || array_key_exists('attendees', $arguments);
        if (!$hasChange) {
            return ['error' => 'What should I change on it?'];
        }

        $calendarId = $str('calendar_id') ?? 'primary';

        $event = $this->calendar->updateEvent(
            $userId,
            $eventId,
            $str('summary'),
            $str('start'),
            $str('end'),
            $str('description'),
            $str('location'),
            $str('time_zone'),
            $calendarId,
            array_key_exists('all_day', $arguments) ? (bool) $arguments['all_day'] : null,
            isset($arguments['attendees']) && is_array($arguments['attendees'])
                ? array_map('strval', $arguments['attendees']) : null,
            !empty($arguments['notify']),
        );

        if ($event === null) {
            return ['error' => 'No matching event found to update (it may already be gone) — nothing was changed.'];
        }

        return [
            'updated' => true,
            'event'   => $event,
        ];
    }
}
