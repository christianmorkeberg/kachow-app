<?php

declare(strict_types=1);

namespace App\Data;

use App\Auth\GoogleOAuth;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Exception as GoogleServiceException;

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
                $events[] = $this->normalize($event, $calendar['name'], $calendar['id']);
            }
        }

        // Merge-sort across calendars by start time (date or dateTime strings sort
        // lexicographically in the right order for RFC3339 / YYYY-MM-DD).
        usort($events, static fn (array $a, array $b): int => strcmp((string) $a['start'], (string) $b['start']));

        return $events;
    }

    /**
     * Builds a renderable agenda card (kind = agenda) from a set of events,
     * grouped by day and ordered by time. Display-only — calendar events are
     * read from Google, so there are no tickboxes.
     *
     * Times are taken verbatim from each event's own RFC3339 string (which
     * already carries the event's local offset), so no timezone juggling is
     * needed: the date is the first 10 chars, the clock time chars 11–15.
     *
     * @param array<int, array<string, mixed>> $events as returned by getEvents()
     * @return array{kind:string, title:string, days:array<int,array{date:string, weekday:string, label:string, events:array<int,array{time:string, all_day:bool, summary:string, location:?string, calendar:?string}>}>}
     */
    public function buildCard(array $events, string $title): array
    {
        $days = [];
        foreach ($events as $e) {
            $start = (string) ($e['start'] ?? '');
            if ($start === '') {
                continue;
            }
            if ($this->isWeekNumberMarker($e)) {
                continue; // drop "Week 28 of 2026" / "Uge 28" clutter from subscribed calendars
            }
            $dayKey = substr($start, 0, 10);
            if (!isset($days[$dayKey])) {
                $ts = strtotime($dayKey) ?: time();
                $days[$dayKey] = [
                    'date'    => $dayKey,
                    'weekday' => date('D', $ts),  // Mon, Tue…
                    'label'   => date('j M', $ts), // 10 Jul
                    'events'  => [],
                ];
            }

            $allDay = (bool) ($e['allDay'] ?? false);
            $time   = 'All day';
            if (!$allDay) {
                $time = substr($start, 11, 5); // HH:MM
                $end  = (string) ($e['end'] ?? '');
                // Append the end time only if the event ends on the same day.
                if (strlen($end) >= 16 && substr($end, 0, 10) === $dayKey) {
                    $time .= '–' . substr($end, 11, 5);
                }
            }

            $days[$dayKey]['events'][] = [
                'time'     => $time,
                'all_day'  => $allDay,
                'summary'  => (string) ($e['summary'] ?? '(no title)'),
                'location' => ($e['location'] ?? null) ? (string) $e['location'] : null,
                'calendar' => ($e['calendar'] ?? null) ? (string) $e['calendar'] : null,
            ];
        }

        ksort($days);

        return ['kind' => 'agenda', 'title' => $title, 'days' => array_values($days)];
    }

    /**
     * True for the all-day "week number" events some calendars publish on Mondays
     * (e.g. "Week 28 of 2026", Danish "Uge 28") — noise we drop from the agenda.
     */
    private function isWeekNumberMarker(array $event): bool
    {
        if (!($event['allDay'] ?? false)) {
            return false;
        }
        $summary = trim((string) ($event['summary'] ?? ''));

        return $summary !== ''
            && (bool) preg_match('/^(week|uge|wk)\s*\d+\b/i', $summary);
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
        string $timeZone = 'UTC',
        string $calendarId = self::PRIMARY
    ): array {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $event = new GoogleEvent([
            'summary'     => $summary,
            'description' => $description,
            'location'    => $location,
            'start'       => ['dateTime' => $start, 'timeZone' => $timeZone],
            'end'         => ['dateTime' => $end,   'timeZone' => $timeZone],
        ]);

        $targetId = $calendarId !== '' ? $calendarId : self::PRIMARY;
        $created  = $service->events->insert($targetId, $event);

        return $this->normalize($created, $targetId === self::PRIMARY ? 'primary' : $targetId, $targetId);
    }

    /**
     * Deletes an event by id from the given calendar (default primary). Returns
     * true if deleted, false if it was already gone / not found. Any other API
     * error propagates.
     */
    public function deleteEvent(int $userId, string $eventId, string $calendarId = self::PRIMARY): bool
    {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        try {
            $service->events->delete($calendarId !== '' ? $calendarId : self::PRIMARY, $eventId);
            return true;
        } catch (GoogleServiceException $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                return false; // not found or already deleted
            }
            throw $e;
        }
    }

    /**
     * Lists all of the user's calendars with ids, names, and whether they can be
     * written to. Used so the assistant can resolve a calendar the user names
     * (e.g. a shared calendar) to its id before reading or inserting.
     *
     * @return array<int, array{id: string, name: string, primary: bool, access_role: string, can_write: bool}>
     */
    /**
     * Events on one local day (Europe/Copenhagen), optionally only from the
     * calendar with the given name (case-insensitive) — used to read the user's
     * "Arbejde" work schedule.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventsForDay(int $userId, string $localDate, ?string $calendarName = null): array
    {
        $tz   = new \DateTimeZone('Europe/Copenhagen');
        $from = (new \DateTimeImmutable($localDate . ' 00:00:00', $tz))->format(DATE_RFC3339);
        $to   = (new \DateTimeImmutable($localDate . ' 23:59:59', $tz))->format(DATE_RFC3339);

        $events = $this->getEvents($userId, $from, $to);
        if ($calendarName !== null && $calendarName !== '') {
            $needle = mb_strtolower($calendarName);
            $events = array_values(array_filter(
                $events,
                static fn (array $e): bool => mb_strtolower((string) ($e['calendar'] ?? '')) === $needle
            ));
        }

        return $events;
    }

    public function listCalendars(int $userId): array
    {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $calendars = [];
        foreach ($service->calendarList->listCalendarList()->getItems() as $entry) {
            $role = (string) $entry->getAccessRole();
            $calendars[] = [
                'id'          => (string) $entry->getId(),
                'name'        => (string) ($entry->getSummaryOverride() ?: $entry->getSummary()),
                'primary'     => (bool) $entry->getPrimary(),
                'access_role' => $role,
                'can_write'   => in_array($role, ['owner', 'writer'], true),
            ];
        }

        return $calendars;
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
    private function normalize(GoogleEvent $event, ?string $calendarName = null, ?string $calendarId = null): array
    {
        $start = $event->getStart();
        $end   = $event->getEnd();

        return [
            'id'          => $event->getId(),
            'calendar'    => $calendarName,
            'calendar_id' => $calendarId,
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
