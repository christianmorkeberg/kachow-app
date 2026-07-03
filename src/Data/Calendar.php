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
 * Every call hits the Google API against the user's **primary** calendar. Token
 * management is delegated to Auth/GoogleOAuth; this class only reads/writes events.
 */
final class Calendar
{
    private const CALENDAR_ID = 'primary';

    public function __construct(
        private GoogleOAuth $oauth,
    ) {
    }

    /**
     * Returns events on the primary calendar within [$from, $to].
     *
     * $from/$to are RFC3339 timestamps (e.g. '2026-07-10T00:00:00Z'). Recurring
     * events are expanded to individual instances, ordered by start time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEvents(int $userId, string $from, string $to, int $maxResults = 250): array
    {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $result = $service->events->listEvents(self::CALENDAR_ID, [
            'timeMin'      => $from,
            'timeMax'      => $to,
            'singleEvents' => true,
            'orderBy'      => 'startTime',
            'maxResults'   => $maxResults,
        ]);

        $events = [];
        foreach ($result->getItems() as $event) {
            $events[] = $this->normalize($event);
        }

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

        $created = $service->events->insert(self::CALENDAR_ID, $event);

        return $this->normalize($created);
    }

    /**
     * Flattens a Google Event object into a plain array the tool layer can hand
     * back to the model. Handles both timed events (dateTime) and all-day events
     * (date only).
     *
     * @return array<string, mixed>
     */
    private function normalize(GoogleEvent $event): array
    {
        $start = $event->getStart();
        $end   = $event->getEnd();

        return [
            'id'          => $event->getId(),
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
