<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\WorkClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Location tracking phase 4, SHADOW mode: derive work sessions from stays at work-type places
 * (via Timeline + WorkClock) and compare them, day by day, with the recorded clock (the iOS
 * Shortcut punches in work_events). Nothing is written — this only lets the user judge, over a
 * week or two, whether the automatic clock matches reality before we switch over and retire the
 * Shortcut. The dwell + bridge thresholds are user settings so they can be tuned from what this
 * view shows.
 */
final class WorkClockShadow
{
    private const LOCAL_TZ = 'Europe/Copenhagen';
    private const MAX_DAYS  = 31;

    public function __construct(
        private Timeline $timeline,
        private WorkEvents $events,
        private UserSettings $settings,
    ) {
    }

    /** Dwell / bridge thresholds (settings, clamped). */
    public function params(int $userId): array
    {
        $g = fn (string $k, int $min, int $max, int $def): int =>
            max($min, min($max, (int) round((float) ($this->settings->get($userId, $k) ?? $def))));

        return [
            'min_dwell_minutes' => $g('workclock_min_dwell_minutes', 3, 60, WorkClock::DEFAULTS['min_dwell_minutes']),
            'max_bridge_hours'  => $g('workclock_max_bridge_hours', 0, 8, WorkClock::DEFAULTS['max_bridge_hours']),
        ];
    }

    /**
     * Per-day comparison of the location clock vs the recorded punches over an inclusive local
     * date range (capped at 31 days), newest day first, plus a renderable card.
     *
     * @return array<string, mixed>
     */
    public function compare(int $userId, string $fromDate, string $toDate): array
    {
        $tz     = new DateTimeZone(self::LOCAL_TZ);
        $params = $this->params($userId);

        $start = (new DateTimeImmutable($fromDate, $tz))->setTime(0, 0);
        $end   = (new DateTimeImmutable($toDate, $tz))->setTime(0, 0);
        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }
        // Clamp the span.
        if ($start->diff($end)->days + 1 > self::MAX_DAYS) {
            $start = $end->modify('-' . (self::MAX_DAYS - 1) . ' days');
        }

        $rows = [];
        $totLoc = 0; $totPunch = 0; $agree = 0; $dayCount = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            [$from, $to] = LocationPoints::dayBounds($date);

            $a = $this->timeline->analyse($userId, $from, $to);
            $workStays = [];
            foreach ($a['stays'] as $s) {
                if (($s['type'] ?? null) === 'work') {
                    $workStays[] = $s;
                }
            }
            $sessions = WorkClock::sessions($workStays, $from, $to, $params);

            $locMin = 0;
            $locSessions = [];
            foreach ($sessions as $s) {
                $locMin += $s['minutes'];
                $locSessions[] = [
                    'place'    => $s['place'],
                    'in'       => self::localTime($s['in']),
                    'out'      => $s['ongoing'] ? null : self::localTime($s['out']),
                    'ongoing'  => $s['ongoing'],
                    'duration' => self::fmt($s['minutes']),
                ];
            }

            $punch    = $this->events->summary($userId, 'today', $date);
            $punchMin = (int) $punch['total_minutes'];
            $punchSessions = array_map(static fn (array $ps): array => [
                'place'    => $ps['place'],
                'in'       => $ps['in'],
                'out'      => $ps['out'],
                'ongoing'  => $ps['ongoing'],
                'duration' => $ps['duration'],
            ], $punch['sessions']);

            // A day "agrees" when the two clocks are within 15 minutes.
            $delta = $locMin - $punchMin;
            if ($locMin > 0 || $punchMin > 0) {
                $dayCount++;
                if (abs($delta) <= 15) {
                    $agree++;
                }
            }
            $totLoc += $locMin; $totPunch += $punchMin;

            $rows[] = [
                'date'             => $date,
                'weekday'          => $d->format('D'),
                'label'            => $d->format('D j M'),
                'location_minutes' => $locMin,
                'location_total'   => self::fmt($locMin),
                'location_sessions' => $locSessions,
                'punch_minutes'    => $punchMin,
                'punch_total'      => self::fmt($punchMin),
                'punch_sessions'   => $punchSessions,
                'delta_minutes'    => $delta,
                'delta_label'      => ($delta > 0 ? '+' : '') . self::fmt($delta),
                'match'            => ($locMin > 0 || $punchMin > 0) ? abs($delta) <= 15 : null,
            ];
        }

        $rows = array_reverse($rows); // newest first

        $card = [
            'kind'        => 'work_clock',
            'title'       => 'Work clock · shadow',
            'range'       => $start->format('j M') . ' – ' . $end->format('j M Y'),
            'location_total' => self::fmt($totLoc),
            'punch_total'    => self::fmt($totPunch),
            'agree_days'  => $agree,
            'compared_days' => $dayCount,
            'params'      => $params,
            'days'        => $rows,
            'note'        => 'Shadow only — not counted in your hours yet. The location clock reads work-type '
                . 'places; punches are your current clock.',
        ];

        return [
            'range'          => $card['range'],
            'location_total' => self::fmt($totLoc),
            'punch_total'    => self::fmt($totPunch),
            'agree_days'     => $agree,
            'compared_days'  => $dayCount,
            'days'           => $rows,
            'card'           => $card,
        ];
    }

    private static function localTime(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(self::LOCAL_TZ))->format('H:i');
    }

    private static function fmt(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $sign . $m . 'm';
        }
        return $sign . ($m === 0 ? $h . 'h' : $h . 'h ' . $m . 'm');
    }
}
