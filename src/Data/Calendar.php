<?php

declare(strict_types=1);

namespace App\Data;

use App\Auth\GoogleOAuth;
use DateTimeImmutable;
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

    /** The zone every event time is shown in (and naive times are read in): Google returns each event in ITS calendar's own zone. */
    public const DISPLAY_TZ = 'Europe/Copenhagen';

    /**
     * An RFC3339 date-time moved to the display zone (same instant, local offset), so the date and clock digits are what the user
     * lives by — whatever zone the source calendar is set to (report #76: a +08:00 calendar showed 17:00 as 00:00 the next day).
     * Date-only (all-day) values and anything unparsable are returned unchanged.
     */
    public static function localise(?string $dateTime): ?string
    {
        if ($dateTime === null || strlen($dateTime) <= 10) {
            return $dateTime;
        }
        try {
            return (new DateTimeImmutable($dateTime))->setTimezone(new \DateTimeZone(self::DISPLAY_TZ))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return $dateTime;
        }
    }

    /** Whether a date-time string says which zone it is in (a trailing Z or ±hh:mm / ±hhmm). */
    public static function hasOffset(string $dateTime): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($dateTime));
    }

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
                'timeZone'     => self::DISPLAY_TZ,   // ask Google for the user's zone, not each calendar's own (normalize() enforces it too)
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
     * The local dates a month grid shows: Monday of the week holding the 1st through Sunday of the
     * week holding the last day (5 or 6 rows). $month is "YYYY-MM".
     *
     * @return array{0: string, 1: string} inclusive local dates
     */
    public static function monthGrid(string $month): array
    {
        $tz    = new \DateTimeZone('Europe/Copenhagen');
        $first = new \DateTimeImmutable($month . '-01', $tz);
        $last  = $first->modify('last day of this month');
        $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $end   = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * The events of each local day from $gridFrom to $gridTo (inclusive), keyed by date: a multi-day event appears on each of its days ("cont." after the first),
     * all-day first then by time. Shared by the month grid and the day / 3-day / week views.
     *
     * @param array<int, array<string, mixed>> $events as returned by getEvents()
     * @return array<string, list<array<string, mixed>>>
     */
    private function dayMap(array $events, string $gridFrom, string $gridTo): array
    {
        $days = [];
        foreach ($events as $e) {
            $start = (string) ($e['start'] ?? '');
            if ($start === '' || $this->isWeekNumberMarker($e)) {
                continue;
            }
            $allDay  = (bool) ($e['allDay'] ?? false);
            $end     = (string) ($e['end'] ?? '');
            $first   = substr($start, 0, 10);
            // All-day ends are exclusive dates; a timed event ending exactly at midnight ends the day before.
            $lastDay = $first;
            if ($end !== '') {
                $endDay = substr($end, 0, 10);
                if ($allDay || substr($end, 11, 5) === '00:00') {
                    $endDay = date('Y-m-d', (int) strtotime($endDay . ' -1 day'));
                }
                $lastDay = max($first, $endDay);
            }
            $time = 'All day';
            if (!$allDay) {
                $time = substr($start, 11, 5);
                if (strlen($end) >= 16 && substr($end, 0, 10) === $first) {
                    $time .= '–' . substr($end, 11, 5);
                }
            }
            $entry = [
                'time'     => $time,
                'all_day'  => $allDay,
                'summary'  => (string) ($e['summary'] ?? '(no title)'),
                'location' => ($e['location'] ?? null) ? (string) $e['location'] : null,
                'calendar' => ($e['calendar'] ?? null) ? (string) $e['calendar'] : null,
            ];
            for ($d = max($first, $gridFrom), $n = 0; $d <= min($lastDay, $gridTo) && $n < 62; $n++) {
                $days[$d][] = $d === $first ? $entry : ['time' => $allDay ? 'All day' : 'cont.'] + $entry;
                $d = date('Y-m-d', (int) strtotime($d . ' +1 day'));
            }
        }
        foreach ($days as $k => $list) {
            // All-day first, then by time.
            usort($list, static fn (array $a, array $b): int => [!$a['all_day'], $a['time']] <=> [!$b['all_day'], $b['time']]);
            $days[$k] = $list;
        }
        ksort($days);

        return $days;
    }

    public const VIEWS = ['day', '3day', 'week', 'month'];

    /**
     * The local dates (inclusive) a view shows around $anchor ("YYYY-MM-DD"): day = that day, 3day = it and the next two, week = Monday–Sunday of its week, month = the month grid.
     *
     * @return array{0: string, 1: string}
     */
    public static function viewRange(string $view, string $anchor): array
    {
        $tz = new \DateTimeZone('Europe/Copenhagen');
        $a  = new \DateTimeImmutable($anchor, $tz);

        return match ($view) {
            'day'   => [$a->format('Y-m-d'), $a->format('Y-m-d')],
            '3day'  => [$a->format('Y-m-d'), $a->modify('+2 days')->format('Y-m-d')],
            'week'  => [$a->modify('-' . ((int) $a->format('N') - 1) . ' days')->format('Y-m-d'), $a->modify('+' . (7 - (int) $a->format('N')) . ' days')->format('Y-m-d')],
            default => self::monthGrid($a->format('Y-m')),
        };
    }

    /** The UTC RFC3339 range to fetch for local dates $from…$to inclusive. @return array{0: string, 1: string} */
    public static function rangeUtc(string $from, string $to): array
    {
        $tz  = new \DateTimeZone('Europe/Copenhagen');
        $utc = new \DateTimeZone('UTC');

        return [
            (new \DateTimeImmutable($from, $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            (new \DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * The one calendar card (kind = calendar_month) in any view — day, 3 days, week or month — anchored at a local date. The month view is the month grid card; the others
     * list the days of the range (empty days included, so "nothing on" is visible). prev / next are the anchors one step away (a month for the month view).
     *
     * @param array<int, array<string, mixed>> $events as returned by getEvents() for rangeUtc(viewRange(...))
     * @return array<string, mixed>
     */
    public function buildViewCard(array $events, string $view, string $anchor): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'month';
        $tz   = new \DateTimeZone('Europe/Copenhagen');
        $a    = new \DateTimeImmutable($anchor, $tz);
        if ($view === 'month') {
            return $this->buildMonthCard($events, $a->format('Y-m')) + ['anchor' => $a->format('Y-m-d')];
        }
        [$from, $to] = self::viewRange($view, $a->format('Y-m-d'));
        $days        = $this->dayMap($events, $from, $to);
        $step        = ['day' => 1, '3day' => 3, 'week' => 7][$view];
        $f           = new \DateTimeImmutable($from, $tz);
        $t           = new \DateTimeImmutable($to, $tz);
        $title       = $view === 'day' ? $f->format('D j M Y') : $f->format('j M') . ' – ' . $t->format('j M Y');

        return [
            'kind'      => 'calendar_month',
            'view'      => $view,
            'anchor'    => $a->format('Y-m-d'),
            'title'     => $title,
            'range_from' => $from,
            'range_to'  => $to,
            'today'     => (new \DateTimeImmutable('now', $tz))->format('Y-m-d'),
            'prev'      => $a->modify('-' . $step . ' days')->format('Y-m-d'),
            'next'      => $a->modify('+' . $step . ' days')->format('Y-m-d'),
            'days'      => $days === [] ? new \stdClass() : $days,
        ];
    }

    /**
     * Renderable month card (kind = calendar_month): the events of every day the grid shows,
     * keyed by local date. A multi-day event appears on each of its days. Display-only, like
     * the agenda; times come verbatim from each event's own RFC3339 string.
     *
     * @param array<int, array<string, mixed>> $events as returned by getEvents()
     * @return array<string, mixed>
     */
    public function buildMonthCard(array $events, string $month): array
    {
        [$gridFrom, $gridTo] = self::monthGrid($month);
        $days = $this->dayMap($events, $gridFrom, $gridTo);

        $tz = new \DateTimeZone('Europe/Copenhagen');
        $m  = new \DateTimeImmutable($month . '-01', $tz);

        return [
            'kind'      => 'calendar_month',
            'view'      => 'month',
            'month'     => $m->format('Y-m'),
            'title'     => $m->format('F Y'),
            'grid_from' => $gridFrom,
            'grid_to'   => $gridTo,
            'today'     => (new \DateTimeImmutable('now', $tz))->format('Y-m-d'),
            'prev'      => $m->modify('-1 month')->format('Y-m'),
            'next'      => $m->modify('+1 month')->format('Y-m'),
            'days'      => $days === [] ? new \stdClass() : $days,
        ];
    }

    /**
     * The UTC RFC3339 range to fetch for a month grid (its first to last shown day, local time).
     *
     * @return array{0: string, 1: string}
     */
    public static function monthRangeUtc(string $month): array
    {
        [$from, $to] = self::monthGrid($month);
        $tz  = new \DateTimeZone('Europe/Copenhagen');
        $utc = new \DateTimeZone('UTC');

        return [
            (new \DateTimeImmutable($from, $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            (new \DateTimeImmutable($to, $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ];
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
     * $start/$end are RFC3339 timestamps. A time that carries its own offset (or Z) means exactly that instant; one without is
     * read in $timeZone (default Europe/Copenhagen — the user's zone; it used to be UTC, which put a plain "12:00" an hour late).
     *
     * $attendees is a list of email addresses — this also covers inviting one of the
     * user's OWN other calendars to block time on it too (a secondary Google Calendar's
     * id is itself an email-shaped address, found via listCalendars). $notify controls
     * whether Google actually emails those addresses (false = silent, used for the
     * self-blocking case; true = a real invitation, for an actual guest).
     *
     * @param array<int, string>|null $attendees
     * @return array<string, mixed>
     */
    public function insertEvent(
        int $userId,
        string $summary,
        string $start,
        string $end,
        ?string $description = null,
        ?string $location = null,
        string $timeZone = self::DISPLAY_TZ,
        string $calendarId = self::PRIMARY,
        bool $allDay = false,
        ?array $attendees = null,
        bool $notify = false
    ): array {
        $service = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));

        $fields = [
            'summary'     => $summary,
            'description' => $description,
            'location'    => $location,
        ];

        if ($allDay) {
            // All-day events use date-only fields (no time, no timezone) so they never
            // drift with the viewer's zone. `end.date` is EXCLUSIVE in Google's API, so
            // we treat the given `end` as the LAST day (inclusive) and add one day — a
            // single all-day event just has start == end.
            $startDate    = substr(trim($start), 0, 10);
            $endInclusive = substr(trim($end), 0, 10);
            if ($endInclusive < $startDate) {
                $endInclusive = $startDate;
            }
            $endExclusive = (new DateTimeImmutable($endInclusive))->modify('+1 day')->format('Y-m-d');

            $fields['start'] = ['date' => $startDate];
            $fields['end']   = ['date' => $endExclusive];
        } else {
            $fields['start'] = ['dateTime' => $start, 'timeZone' => $timeZone];
            $fields['end']   = ['dateTime' => $end,   'timeZone' => $timeZone];
        }

        if ($attendees !== null && $attendees !== []) {
            $fields['attendees'] = $this->attendeeList($attendees);
        }

        $targetId = $calendarId !== '' ? $calendarId : self::PRIMARY;
        $created  = $service->events->insert($targetId, new GoogleEvent($fields), [
            'sendUpdates' => $notify ? 'all' : 'none',
        ]);

        return $this->normalize($created, $targetId === self::PRIMARY ? 'primary' : $targetId, $targetId);
    }

    /**
     * Partially updates an existing event — only the fields given are changed; everything
     * else on the event is left as-is. When start, end, or all_day is given, the current
     * event is fetched first so the untouched side of the time range (and whether it's an
     * all-day vs timed event) carries over correctly, same inclusive/exclusive end-date
     * handling as insertEvent. Returns null if the event doesn't exist (already deleted, or
     * a bad id) instead of throwing, so the tool layer can give a friendly error.
     *
     * $attendees, when not null, REPLACES the event's whole attendee list (Google's patch
     * semantics for this field) — pass [] to clear it, or the full desired list including
     * anyone already on it that should stay. See insertEvent for what the values can be.
     *
     * @param array<int, string>|null $attendees
     * @return array<string, mixed>|null
     */
    public function updateEvent(
        int $userId,
        string $eventId,
        ?string $summary = null,
        ?string $start = null,
        ?string $end = null,
        ?string $description = null,
        ?string $location = null,
        ?string $timeZone = null,
        string $calendarId = self::PRIMARY,
        ?bool $allDay = null,
        ?array $attendees = null,
        bool $notify = false
    ): ?array {
        $service  = new GoogleCalendar($this->oauth->authorizedClientForUser($userId));
        $targetId = $calendarId !== '' ? $calendarId : self::PRIMARY;

        try {
            $patch = [];
            if ($summary !== null) {
                $patch['summary'] = $summary;
            }
            if ($description !== null) {
                $patch['description'] = $description;
            }
            if ($location !== null) {
                $patch['location'] = $location;
            }

            if ($start !== null || $end !== null || $allDay !== null) {
                $current   = $service->events->get($targetId, $eventId);
                $wasAllDay = $current->getStart() !== null && $current->getStart()->getDateTime() === null;
                $nowAllDay = $allDay ?? $wasAllDay;
                $startVal  = $start ?? ($wasAllDay ? $current->getStart()->getDate() : $current->getStart()->getDateTime());
                $endVal    = $end   ?? ($wasAllDay ? $current->getEnd()->getDate()   : $current->getEnd()->getDateTime());
                // A time the model gave without an offset is the user's local time — not the event's own zone (a shared calendar may be set to another one).
                $naive     = ($start !== null && !self::hasOffset($start)) || ($end !== null && !self::hasOffset($end));
                $tz        = $timeZone ?? ($naive ? self::DISPLAY_TZ : ($current->getStart()->getTimeZone() ?: self::DISPLAY_TZ));

                if ($nowAllDay) {
                    $startDate    = substr(trim((string) $startVal), 0, 10);
                    $endInclusive = substr(trim((string) $endVal), 0, 10);
                    if ($endInclusive < $startDate) {
                        $endInclusive = $startDate;
                    }
                    $endExclusive = (new DateTimeImmutable($endInclusive))->modify('+1 day')->format('Y-m-d');
                    $patch['start'] = ['date' => $startDate];
                    $patch['end']   = ['date' => $endExclusive];
                } else {
                    $patch['start'] = ['dateTime' => $startVal, 'timeZone' => $tz];
                    $patch['end']   = ['dateTime' => $endVal, 'timeZone' => $tz];
                }
            }

            if ($attendees !== null) {
                $patch['attendees'] = $this->attendeeList($attendees);
            }

            if ($patch === []) {
                return null;
            }

            $updated = $service->events->patch($targetId, $eventId, new GoogleEvent($patch), [
                'sendUpdates' => $notify ? 'all' : 'none',
            ]);

            return $this->normalize($updated, $targetId === self::PRIMARY ? 'primary' : $targetId, $targetId);
        } catch (GoogleServiceException $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                return null;
            }
            throw $e;
        }
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

        $attendees = [];
        foreach ($event->getAttendees() ?? [] as $a) {
            $attendees[] = [
                'email'           => $a->getEmail(),
                'response_status' => $a->getResponseStatus(),
            ];
        }

        return [
            'id'          => $event->getId(),
            'calendar'    => $calendarName,
            'calendar_id' => $calendarId,
            'summary'     => $event->getSummary(),
            'description' => $event->getDescription(),
            'location'    => $event->getLocation(),
            'start'       => $start ? self::localise($start->getDateTime() ?? $start->getDate()) : null,
            'end'         => $end ? self::localise($end->getDateTime() ?? $end->getDate()) : null,
            'allDay'      => $start ? ($start->getDateTime() === null) : null,
            'attendees'   => $attendees,
            'htmlLink'    => $event->getHtmlLink(),
        ];
    }

    /**
     * Builds the plain-array form of an attendee list (email strings -> Google's
     * {email: ...} shape) that both insertEvent and updateEvent send.
     *
     * @param array<int, string> $emails
     * @return array<int, array{email: string}>
     */
    private function attendeeList(array $emails): array
    {
        return array_map(
            static fn (string $email): array => ['email' => trim($email)],
            array_values(array_filter($emails, static fn ($e): bool => trim((string) $e) !== ''))
        );
    }
}
