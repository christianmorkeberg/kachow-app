<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\LocationClockPlan;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Location tracking phase 4, LIVE: when the user has switched the work clock to location
 * (setting work_clock = location), keep each day's work_events in line with the work sessions
 * location sees — written with source 'location', so every hours card, tool and invoice keeps
 * working unchanged. Run as positions arrive (api/owntracks.php) and once more the next morning
 * (bin/notify-cron.php) to finalise yesterday.
 *
 * Rules (decided with Christian, 2026-10-17):
 *  - Opt-in: nothing happens until the setting is flipped; Shortcut punches are ignored after that.
 *  - Manual wins: a day holding any punch NOT made by location (chat, Shortcut), or where the user
 *    deleted a location punch, is never touched again.
 *  - A visit touching a tracking gap is skipped (not guessed) and reported, so the user can fill it in.
 */
final class LocationWorkClock
{
    public function __construct(
        private WorkClockShadow $shadow,
        private WorkEvents $events,
        private UserSettings $settings,
    ) {
    }

    public static function standard(): self
    {
        $settings = new UserSettings();
        $events   = new WorkEvents();

        return new self(new WorkClockShadow(new Timeline(new LocationPoints(), new Places(), $settings), $events, $settings), $events, $settings);
    }

    public function isOn(int $userId): bool
    {
        return $this->settings->get($userId, 'work_clock') === 'location';
    }

    /** A day the user has fixed by hand — the location clock leaves it alone. */
    public function isManual(int $userId, string $localDate): bool
    {
        if ($this->events->isManualDay($userId, $localDate)) {
            return true;
        }
        [$from, $to] = LocationPoints::dayBounds($localDate);
        foreach ($this->events->eventsWithSource($userId, gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)) as $e) {
            if ($e['source'] !== WorkEvents::SOURCE_LOCATION) {
                return true;
            }
        }

        return false;
    }

    /**
     * Brings one local day's location punches up to date.
     *
     * @return array{status: string, inserted?: int, deleted?: int, uncertain?: list<array{place:?string, in:string, out:?string}>}
     *         status: off (setting not on) | manual (day fixed by hand) | synced
     */
    public function sync(int $userId, string $localDate): array
    {
        if (!$this->isOn($userId)) {
            return ['status' => 'off'];
        }
        if ($this->isManual($userId, $localDate)) {
            return ['status' => 'manual'];
        }

        $day = $this->shadow->daySessions($userId, $localDate);
        [$from, $to] = LocationPoints::dayBounds($localDate);
        $existing = [];
        foreach ($this->events->eventsWithSource($userId, gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)) as $e) {
            $existing[] = [
                'id'       => $e['id'],
                'kind'     => $e['kind'],
                'ts'       => (new DateTimeImmutable($e['occurred_at'], new DateTimeZone('UTC')))->getTimestamp(),
                'location' => $e['location'],
            ];
        }

        $plan = LocationClockPlan::plan($day['sessions'], $day['gaps'], $existing);
        // Remove first: re-adding a moved punch right after must not be mistaken for a duplicate.
        foreach ($plan['delete'] as $id) {
            $this->events->removeLocationEvent($userId, $id);
        }
        foreach ($plan['insert'] as $p) {
            $this->events->add($userId, $p['kind'], gmdate('Y-m-d H:i:s', $p['ts']), WorkEvents::SOURCE_LOCATION, null, $p['location']);
        }

        $tz = new DateTimeZone(WorkEvents::LOCAL_TZ);
        $at = static fn (int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:i');

        return [
            'status'    => 'synced',
            'inserted'  => count($plan['insert']),
            'deleted'   => count($plan['delete']),
            'uncertain' => array_map(static fn (array $s): array => [
                'place' => $s['place'] ?? null,
                'in'    => $at((int) $s['in']),
                'out'   => empty($s['ongoing']) ? $at((int) $s['out']) : null,
            ], $plan['uncertain']),
        ];
    }
}
