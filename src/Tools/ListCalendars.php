<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: list the user's calendars. Thin wrapper over Data\Calendar::listCalendars.
 */
final class ListCalendars implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'list_calendars';
    }

    public function description(): string
    {
        return "Lists the user's Google Calendars with their ids, names, and whether each can be "
            . 'written to. Use this to find the id of a calendar the user names (e.g. a calendar shared '
            . 'with someone) before adding an event to it with insert_calendar_event. Only calendars '
            . 'where "can_write" is true can have events added.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $calendars = $this->calendar->listCalendars($userId);

        return [
            'count'     => count($calendars),
            'calendars' => $calendars,
        ];
    }
}
