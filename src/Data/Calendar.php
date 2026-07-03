<?php

declare(strict_types=1);

namespace App\Data;

use App\Auth\GoogleOAuth;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;

/**
 * Data-access layer for the user's calendar.
 *
 * Unlike the other Data/ classes this does NOT talk to MySQL — Google Calendar
 * is the live source of truth (spec §5: deliberately no calendar_events table).
 * Token management is delegated to Auth/GoogleOAuth; this class only reads/writes
 * events.
 *
 * Reads span every calendar the user has *visible* in Google Calendar (their
 * primary plus any shared/selected calendars), each event tagged with its source
 * calendar. Writes go to the primary calendar.
 */
final class Calendar
{
    private const PRIMARY = 'primary';

    public function __construct(
        private GoogleOAuth $oauth,
    ) {
    }

    /**
     * Returns events within [$from, $to] across all of the user's visible
     * calendars (primary + shared/selected), merged and ordered by start time.
     * Each event includes a "calendar" field naming its source calendar.
     *
     * $from/$to are RFC3339 timestamps (e.g. '2026-07-10T00:00:00Z'). Recurring
     * events are expanded to individual instances.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(int $userId, string $from, string $to, int $maxResultsPerCalendar = 250): array
    {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $events = [];
        foreach ($this->visibleCalendars($service) as $calendar) {
            $result = $service->events->listEvents($calendar['id'], [
                'timeMin'      => $from,
                'timeMax'      => $to,
                'singleEvents' => true,
                'orderBy'      => 'startTime',
                'maxResults'   => $maxResultsPerCalendar,
            ]);

            foreach ($result->getItems() as $event) {
                $events[] = $this->normalize($event, $calendar['name']);
            }
        }

        // Merge-sort across calendars by start time (date or dateTime strings sort
        // lexicographically in the right order for RFC3339 / YYYY-MM-DD).
        usort($events, static fn (array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));

        return $events;
    }

    /**
     * Creates an event on the primary calendar and returns the created event.
     *
     * $start/$end are RFC3339 timestamps. Times are interpreted in $timeZone
     * (default UTC — the app runs in UTC and the model is expected to pass UTC).
     *
     * @return array<string, mixed>
     */
    public function insertEvent(
        int $userId,
        string $summary,
        string $start,
        string $end,
        ?string $description = null,
        ?string $location = null,
        string $timeZone = 'UTC'
    ): array {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $event = new GoogleEvent([
            'summary'     => $summary,
            'description' => $description,
            'location'    => $location,
            'start'       => ['dateTime' => $start, 'timeZone' => $timeZone],
            'end'         => ['dateTime' => $end,   'timeZone' => $timeZone],
        ]);

        $created = $service->events->insert(self::PRIMARY, $event);

        return $this->normalize($created, 'primary');
    }

    /**
     * The calendars to read from: primary plus any the user keeps selected
     * (visible) in Google Calendar. Hidden calendars (e.g. deselected holiday or
     * subscription calendars) are skipped so results match what the user sees.
     *
     * @return array<int, array{id: string, name: string, primary: bool}>
     */
    private function visibleCalendars(GoogleCalendar $service): array
    {
        $calendars = [];
        foreach ($service->calendarList->listCalendarList()->getItems() as $entry) {
            $isPrimary = (bool) $entry->getPrimary();
            if (!$isPrimary && !$entry->getSelected()) {
                continue;
            }
            $calendars[] = [
                'id'      => (string) $entry->getId(),
                'name'    => (string) ($entry->getSummaryOverride() ?: $entry->getSummary()),
                'primary' => $isPrimary,
            ];
        }

        // Fallback: if the list came back empty for any reason, still read primary.
        if ($calendars === []) {
            $calendars[] = ['id' => self::PRIMARY, 'name' => 'primary', 'primary' => true];
        }

        return $calendars;
    }

    /**
     * Flattens a Google Event object into a plain array the tool layer hands back
     * to the model. Handles timed (dateTime) and all-day (date) events, and tags
     * it with the source calendar name.
     *
     * @return array<string, mixed>
     */
    private function normalize(GoogleEvent $event, ?string $calendarName = null): array
    {
        $start = $event->getStart();
        $end   = $event->getEnd();

        return [
            'id'          => $event->getId(),
            'calendar'    => $calendarName,
            'summary'     => $event->getSummary(),
            'description' => $event->getDescription(),
            'location'    => $event->getLocation(),
            'start'       => $start ? ($start->getDateTime() ?? $start->getDate()) : null,
            'end'         => $end ? ($end->getDateTime() ?? $end->getDate()) : null,
            'allDay'      => $start ? ($start->getDateTime() === null) : null,
            'htmlLink'    => $event->getHtmlLink(),
        ];
    }
}
