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

        // The card is the Calendar card in the view that fits the range (a day, three days, a week, else the month) — not a card of its own. If that view is wider
        // than the range asked for, it fetches its own days.
        $tz      = new \DateTimeZone(Calendar::DISPLAY_TZ);
        $start   = new \DateTimeImmutable($from);
        $days    = max(1, (int) ceil(((new \DateTimeImmutable($to))->getTimestamp() - $start->getTimestamp()) / 86400));
        $view    = $days <= 1 ? 'day' : ($days <= 3 ? '3day' : ($days <= 7 ? 'week' : 'month'));
        $anchor  = $start->setTimezone($tz)->format('Y-m-d');
        [$vf, $vt] = Calendar::viewRange($view, $anchor);
        [$uf, $ut] = Calendar::rangeUtc($vf, $vt);
        $viewEvents = ($uf === $from && $ut === $to) ? $events : $this->calendar->getEvents($userId, $uf, $ut);

        // The app renders these as an interactive agenda card (see _render); the
        // model gets the events too so it can answer specific questions (e.g.
        // "am I free Friday?"), but it should summarise rather than re-list them.
        return [
            'count'   => count($events),
            'events'  => $events,
            'note'    => 'The app already shows these events in the Calendar card (day / 3-day / week / month view). Use them to answer the '
                . 'user\'s question (e.g. "am I free Friday?") or give a brief summary — do NOT re-list '
                . 'every event line by line as text.',
            '_render' => $this->calendar->buildViewCard($viewEvents, $view, $anchor),
        ];
    }
}
