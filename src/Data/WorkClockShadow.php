<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\WorkClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The work sessions the location clock derives from stays at work-type places (via Timeline + WorkClock): the dwell + bridge thresholds are user settings, and
 * LocationWorkClock turns a day's sessions into work_events. (The old "shadow" comparison card against the iOS Shortcut punches is gone, with the Shortcut link.)
 */
final class WorkClockShadow
{
    private const LOCAL_TZ = 'Europe/Copenhagen';

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
     * One local day as the location clock sees it: work sessions (stays at work-type places, with
     * the dwell + bridge rules) and the day's no-data gaps. Shared by this comparison and by the
     * live location clock (LocationWorkClock), so both judge a day the same way.
     *
     * @return array{sessions: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    public function daySessions(int $userId, string $date, ?array $params = null): array
    {
        [$from, $to] = LocationPoints::dayBounds($date);
        $a = $this->timeline->analyse($userId, $from, $to);
        $workStays = [];
        foreach ($a['stays'] as $s) {
            if (($s['type'] ?? null) === 'work') {
                $workStays[] = $s;
            }
        }

        return [
            'sessions' => WorkClock::sessions($workStays, $from, $to, $params ?? $this->params($userId)),
            'gaps'     => $a['gaps'],
        ];
    }
}
