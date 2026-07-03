<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: delete a calendar event. Thin wrapper over Data\Calendar::deleteEvent.
 */
final class DeleteCalendarEvent implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'delete_calendar_event';
    }

    public function description(): string
    {
        return "Deletes an event from the user's Google Calendar — to cancel it or fix a mistake. "
            . 'First call get_calendar_events to find the event and its "id" (and its "calendar_id" if '
            . 'it is not on the primary calendar); then pass those here. Only delete an event the user '
            . 'clearly identified; if which event is ambiguous, confirm first. This is permanent.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id' => [
                    'type'        => 'string',
                    'description' => 'The id of the event to delete (from get_calendar_events).',
                ],
                'calendar_id' => [
                    'type'        => 'string',
                    'description' => 'The id of the calendar the event is on (the event\'s "calendar_id" '
                        . 'from get_calendar_events). Omit if it is on the primary calendar.',
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

        $calendarId = isset($arguments['calendar_id']) && $arguments['calendar_id'] !== ''
            ? (string) $arguments['calendar_id']
            : 'primary';

        $deleted = $this->calendar->deleteEvent($userId, $eventId, $calendarId);

        return $deleted
            ? ['deleted' => true]
            : ['deleted' => false, 'error' => 'No matching event found (it may already be gone).'];
    }
}
