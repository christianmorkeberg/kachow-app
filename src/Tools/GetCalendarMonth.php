<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;

/**
 * Tool: a whole month of the user's calendars as a month-grid card (with prev/next on the card).
 */
final class GetCalendarMonth implements Tool
{
    public function __construct(private Calendar $calendar)
    {
    }

    public function name(): string
    {
        return 'get_calendar_month';
    }

    public function description(): string
    {
        return 'Opens the Calendar card (all visible calendars, week starts Monday) in a view: "month" (default, a month grid), "week", "3day" or "day"; the card can switch view and page '
            . 'by itself. Use for "show my month", "show October", "what does next month look like", "show my week", "open the calendar", Danish "vis min måned", "månedsoversigt", '
            . '"kalenderen for november", "vis ugen". For a specific question ("am I free Friday?") use get_calendar_events. The '
            . 'card shows the events — give a brief summary (busy days, notable events), don\'t list them all.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'month' => ['type' => 'string', 'description' => 'Month as "YYYY-MM"; defaults to the current month (month view).'],
                'view'  => ['type' => 'string', 'enum' => Calendar::VIEWS, 'description' => 'day, 3day, week or month (default month).'],
                'date'  => ['type' => 'string', 'description' => 'For day / 3day / week: the date to open on, "YYYY-MM-DD" (default today).'],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $view = (string) ($arguments['view'] ?? 'month');
        $date = trim((string) ($arguments['date'] ?? ''));
        if (in_array($view, ['day', '3day', 'week'], true)) {
            $date = $date !== '' ? $date : (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                return ['error' => 'date must look like "2026-11-13".'];
            }
            [$vf, $vt] = Calendar::viewRange($view, $date);
            [$uf, $ut] = Calendar::rangeUtc($vf, $vt);
            $events = $this->calendar->getEvents($userId, $uf, $ut);
            $card   = $this->calendar->buildViewCard($events, $view, $date);
            $perDay = [];
            foreach ((array) $card['days'] as $d => $list) {
                $perDay[$d] = array_map(static fn (array $e): string => ($e['all_day'] ? '' : $e['time'] . ' ') . $e['summary'], $list);
            }

            return ['view' => $view, 'range' => $card['title'], 'events' => $perDay, 'note' => 'The app shows this in the Calendar card. Give a short summary.', '_render' => $card];
        }
        $month = trim((string) ($arguments['month'] ?? ''));
        if ($month === '') {
            $month = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Copenhagen')))->format('Y-m');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return ['error' => 'month must look like "2026-11".'];
        }

        [$from, $to] = Calendar::monthRangeUtc($month);
        $events = $this->calendar->getEvents($userId, $from, $to);
        $card   = $this->calendar->buildMonthCard($events, $month);

        // A compact view for the model: per day, just the titles (the card shows the rest).
        $perDay = [];
        foreach ((array) $card['days'] as $date => $list) {
            if (str_starts_with((string) $date, $month)) {
                $perDay[$date] = array_map(static fn (array $e): string => ($e['all_day'] ? '' : $e['time'] . ' ') . $e['summary'], $list);
            }
        }

        return [
            'month'       => $card['title'],
            'event_days'  => count($perDay),
            'events'      => $perDay,
            'note'        => 'The app shows this as a month grid. Give a short summary, don\'t list every event.',
            '_render'     => $card,
        ];
    }
}
