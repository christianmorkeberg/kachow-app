<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkClockShadow;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: the automatic work clock in SHADOW mode — compare the work hours derived from location
 * (stays at work-type places) with the recorded punches, day by day, so the user can see whether
 * the automatic clock matches before switching over. Read-only; changes no hours. Renders a card.
 */
final class GetWorkClock implements Tool
{
    public function __construct(private WorkClockShadow $shadow)
    {
    }

    public function name(): string
    {
        return 'get_work_clock';
    }

    public function description(): string
    {
        return 'Shows the AUTOMATIC work clock in shadow mode: work hours derived from location (time at '
            . 'work-type places) compared day-by-day with the user\'s recorded punches, so they can check it '
            . 'before we switch over from the manual/Shortcut clock. Use for "how does the automatic clock '
            . 'compare", "location work clock", "auto-stempling", "shadow work hours". Read-only — it does not '
            . 'change any recorded hours. Defaults to the last 7 days; optionally pass a from/to date range. '
            . 'It only reads places typed "work"; if there are none, tell the user to mark their workplace(s) '
            . 'on the places map with type work.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD. Omit for 7 days ago.'],
                'to'   => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (inclusive). Omit for today.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $tz    = new DateTimeZone('Europe/Copenhagen');
        $today = new DateTimeImmutable('now', $tz);

        $to   = isset($arguments['to']) && trim((string) $arguments['to']) !== ''
            ? (new DateTimeImmutable((string) $arguments['to'], $tz))->format('Y-m-d')
            : $today->format('Y-m-d');
        $from = isset($arguments['from']) && trim((string) $arguments['from']) !== ''
            ? (new DateTimeImmutable((string) $arguments['from'], $tz))->format('Y-m-d')
            : $today->modify('-6 days')->format('Y-m-d');

        $res = $this->shadow->compare($userId, $from, $to);

        return [
            'range'          => $res['range'],
            'location_total' => $res['location_total'],
            'punch_total'    => $res['punch_total'],
            'agree_days'     => $res['agree_days'],
            'compared_days'  => $res['compared_days'],
            'days'           => array_map(static fn (array $d): array => [
                'date'     => $d['date'],
                'location' => $d['location_total'],
                'punch'    => $d['punch_total'],
                'delta'    => $d['delta_label'],
                'match'    => $d['match'],
            ], $res['days']),
            '_render'        => $res['card'],
        ];
    }
}
