<?php

declare(strict_types=1);

/**
 * Nightly freeze of each user's finished location days, so stays and trips outlive the 60-day
 * raw-point retention.
 *
 *     40 4 * * * php /home/kachowdk/assistant-app/bin/location-freeze-cron.php >/dev/null 2>&1
 *
 * For every user it walks the local days from the oldest remaining raw point up to yesterday and
 * saves a day's stays/trips/gaps (frozen_location_days) when
 *   - it has never been frozen (this also backfills everything on the first run), or
 *   - it is one of the last 3 days (re-frozen: a night at home crossing midnight is only
 *     complete once the next day's points are in), or
 *   - you asked for it with --redo.
 * A day whose start has already been purged is never overwritten: its frozen copy is the only
 * complete one left.
 *
 * Re-freeze days after changing the location_* settings (only possible while raw points exist):
 *     php bin/location-freeze-cron.php --redo 2026-10-01 2026-10-14      (second date optional)
 *
 * Safe to re-run. Reading code falls back gracefully if the table is missing.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

// Obey the system task's state (paused / owned by the dispatcher) and record what this run came to.
\App\Support\SystemTaskGuard::begin('location_freeze');

use App\Data\FrozenDays;
use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\Timeline;
use App\Data\UserSettings;
use App\Data\Users;
use App\Data\WorkEvents;

const RECENT_DAYS = 3;

$redoFrom = $redoTo = null;
$i = array_search('--redo', $argv, true);
if ($i !== false) {
    $redoFrom = $argv[$i + 1] ?? null;
    $redoTo   = $argv[$i + 2] ?? $redoFrom;
    if ($redoFrom === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $redoFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $redoTo)) {
        fwrite(STDERR, "usage: location-freeze-cron.php [--redo YYYY-MM-DD [YYYY-MM-DD]]\n");
        exit(2);
    }
}

$points   = new LocationPoints();
$frozen   = new FrozenDays();
$timeline = new Timeline($points, new Places(), new UserSettings(), $frozen);
$tz       = new DateTimeZone(WorkEvents::LOCAL_TZ);
$today    = new DateTimeImmutable('today', $tz);
$recentFrom = $today->modify('-' . RECENT_DAYS . ' days')->format('Y-m-d');
$done = 0;
$skip = 0;

foreach ((new Users())->allIds() as $uid) {
    try {
        $earliest = $points->earliestTs($uid);
        if ($earliest === null) {
            continue;
        }
        $day = (new DateTimeImmutable('@' . $earliest))->setTimezone($tz)->setTime(0, 0);
        for (; $day < $today; $day = $day->modify('+1 day')) {
            $date   = $day->format('Y-m-d');
            $has    = $frozen->has($uid, $date);
            $covers = $earliest <= $day->getTimestamp();   // raw points still reach the start of the day
            $forced = $redoFrom !== null && $date >= $redoFrom && $date <= $redoTo;
            $want   = !$has || (($date >= $recentFrom || $forced) && $covers);
            if (!$want) {
                continue;
            }
            $r = $timeline->freezeDay($uid, $date);
            $done++;
            fwrite(STDERR, "user {$uid} {$date}: {$r['stays']} stays, {$r['trips']} trips, {$r['gaps']} gaps\n");
        }
    } catch (\Throwable $e) {
        $skip++;
        error_log('location-freeze-cron user ' . $uid . ': ' . $e->getMessage());
    }
}

fwrite(STDERR, "location-freeze-cron: {$done} day(s) frozen, {$skip} user(s) skipped.\n");
exit(0);
