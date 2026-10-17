<?php

declare(strict_types=1);

/**
 * The LIVE location work clock (phase 4 switch-over): Support\LocationClockPlan (pure) and
 * Data\LocationWorkClock against an in-memory SQLite day of positions. No network:
 *   php -d extension=php_pdo_sqlite bin/location-clock-test.php
 *
 * Coordinates are placeholders on round numbers — never real positions.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\FrozenDays;
use App\Data\LocationPoints;
use App\Data\LocationWorkClock;
use App\Data\Places;
use App\Data\Timeline;
use App\Data\UserSettings;
use App\Data\WorkClockShadow;
use App\Data\WorkEvents;
use App\Support\LocationClockPlan;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/location-clock-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

// ------------------------------------------------------------------ 1) the plan (pure)
echo "1) LocationClockPlan\n";
$s = static fn (int $in, int $out, bool $ongoing = false, string $place = 'Office'): array =>
    ['place' => $place, 'in' => $in, 'out' => $out, 'ongoing' => $ongoing];
$e = static fn (int $id, string $kind, int $ts, string $place = 'Office'): array =>
    ['id' => $id, 'kind' => $kind, 'ts' => $ts, 'location' => $place];

$p = LocationClockPlan::plan([$s(8000, 30000)], [], []);
check('a fresh day: one in and one out', $p['insert'] === [
    ['kind' => 'in', 'ts' => 8000, 'location' => 'Office'], ['kind' => 'out', 'ts' => 30000, 'location' => 'Office'],
] && $p['delete'] === [], json_encode($p));
$p = LocationClockPlan::plan([$s(8000, 30000)], [], [$e(1, 'in', 8030), $e(2, 'out', 29950, 'office')]);
check('a re-run with the same day changes nothing (small drift, any case)', $p['insert'] === [] && $p['delete'] === [], json_encode($p));
check('still at work: only the clock-in', array_column(LocationClockPlan::plan([$s(8000, 20000, true)], [], [])['insert'], 'kind') === ['in']);
$p = LocationClockPlan::plan([$s(8000, 40000)], [], [$e(1, 'in', 8000), $e(2, 'out', 30000)]);
check('came back the same day: the provisional clock-out moves', $p['delete'] === [2] && $p['insert'] === [['kind' => 'out', 'ts' => 40000, 'location' => 'Office']], json_encode($p));
$p = LocationClockPlan::plan([$s(8000, 30000)], [['start' => 30200, 'end' => 36000]], [$e(1, 'in', 8000)]);
check('a gap right after leaving: the visit is skipped and reported', $p['insert'] === [] && count($p['uncertain']) === 1);
check('…and what it wrote earlier for it is taken back', $p['delete'] === [1]);
$p = LocationClockPlan::plan([$s(8000, 30000)], [['start' => 1000, 'end' => 7800]], []);
check('a gap right before arriving is uncertain too', count($p['uncertain']) === 1);
$p = LocationClockPlan::plan([$s(8000, 30000)], [['start' => 40000, 'end' => 50000]], []);
check('a gap hours later does not matter', count($p['insert']) === 2 && $p['uncertain'] === []);
$p = LocationClockPlan::plan([$s(8000, 20000), $s(25000, 30000, false, 'Lab')], [['start' => 30300, 'end' => 40000]], []);
check('only the visit touching the gap is skipped', count($p['insert']) === 2 && $p['insert'][0]['location'] === 'Office' && count($p['uncertain']) === 1);
check('nothing at work: removes stray location punches', LocationClockPlan::plan([], [], [$e(9, 'in', 8000)])['delete'] === [9]);

// ------------------------------------------------------------------ 2) the live clock (SQLite)
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE location_points (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device TEXT, recorded_at TEXT, lat REAL, lon REAL, acc INT, alt INT, vel INT, cog INT, batt INT, conn TEXT, trig TEXT)');
$db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, type TEXT, shape TEXT, lat REAL, lon REAL, radius_m INT, polygon TEXT)');
$db->exec('CREATE TABLE user_settings (user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec('CREATE TABLE frozen_location_days (user_id INT, day TEXT, data TEXT, frozen_at TEXT, PRIMARY KEY (user_id, day))');
$db->exec("CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT,
    source TEXT NOT NULL DEFAULT 'ios_geofence', note TEXT, nudged_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec('CREATE TABLE work_clock_manual_days (user_id INT, day TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, day))');
$db->exec("INSERT INTO places (user_id, name, type, shape, lat, lon, radius_m) VALUES
    (1, 'Home', 'home', 'circle', 55.0, 12.0, 100), (1, 'Office', 'work', 'circle', 55.1, 12.1, 100)");

const HOME   = [55.0, 12.0];
const OFFICE = [55.1, 12.1];
$put = static function (int $t, array $at) use ($db): void {
    $db->prepare('INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc) VALUES (1, "t", ?, ?, ?, 5)')
        ->execute([gmdate('Y-m-d H:i:s', $t), $at[0], $at[1]]);
};
$dwell = static function (int $from, int $to, array $at) use ($put): void { for ($t = $from; $t < $to; $t += 600) { $put($t, $at); } };
$drive = static function (int $from, array $a, array $b) use ($put): void {
    for ($k = 1; $k <= 6; $k++) { $f = $k / 7; $put($from + $k * 180, [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f]); }
};
// 1 Oct 2026 (UTC): home, office 06:50–14:00 UTC (08:50–16:00 local), home.
$t0 = (int) strtotime('2026-10-01 00:00 UTC');
$dwell($t0 - 6 * 3600, $t0 + 6 * 3600 + 1800, HOME);
$drive($t0 + 6 * 3600 + 1800, HOME, OFFICE);
$dwell($t0 + 6 * 3600 + 3000, $t0 + 14 * 3600, OFFICE);
$drive($t0 + 14 * 3600, OFFICE, HOME);
$dwell($t0 + 14 * 3600 + 1500, $t0 + 30 * 3600, HOME);

$settings = new UserSettings($db);
$events   = new WorkEvents($db);
$timeline = new Timeline(new LocationPoints($db), new Places($db), $settings, new FrozenDays($db));
$clock    = new LocationWorkClock(new WorkClockShadow($timeline, $events, $settings), $events, $settings);
$punches  = static fn (): array => $db->query('SELECT kind, occurred_at, location, source FROM work_events ORDER BY occurred_at')->fetchAll();

echo "\n2) off until the user switches\n";
check('setting not on: nothing happens', $clock->sync(1, '2026-10-01') === ['status' => 'off'] && $punches() === []);
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'work_clock', 'location')");

echo "\n3) switched on: the day's work visit becomes punches\n";
$r = $clock->sync(1, '2026-10-01');
$p = $punches();
check('synced: one in, one out', $r['status'] === 'synced' && array_column($p, 'kind') === ['in', 'out'], json_encode([$r, $p]));
check('at the office, marked as from location', $p[0]['location'] === 'Office' && $p[0]['source'] === 'location' && $p[1]['source'] === 'location');
check('clocked in at arrival (08:5x local)', str_starts_with(substr($p[0]['occurred_at'], 11, 5), '06:5'), $p[0]['occurred_at']);
$r = $clock->sync(1, '2026-10-01');
check('running it again changes nothing', $r['inserted'] === 0 && $r['deleted'] === 0 && count($punches()) === 2, json_encode($r));
check('the hours card sees it', $events->summary(1, 'today', '2026-10-01')['total_minutes'] > 400);

echo "\n4) manual wins\n";
$id = (int) $db->query("SELECT id FROM work_events WHERE kind = 'out'")->fetchColumn();
$events->delete(1, $id);
check('deleting a location punch marks the day as fixed by hand', $events->isManualDay(1, '2026-10-01'));
check('…and the clock does not put it back', $clock->sync(1, '2026-10-01')['status'] === 'manual' && count($punches()) === 1);

[$f2] = LocationPoints::dayBounds('2026-10-02');
$dwell($f2 + 6 * 3600, $f2 + 12 * 3600, OFFICE);   // a second day at the office
$events->add(1, 'in', gmdate('Y-m-d H:i:s', $f2 + 5 * 3600), 'assistant', null, 'Office');
check('a day with a punch from chat is left alone', $clock->sync(1, '2026-10-02')['status'] === 'manual');
check('…its own punches untouched', count($events->eventsWithSource(1, gmdate('Y-m-d H:i:s', $f2), gmdate('Y-m-d H:i:s', $f2 + 86400))) === 1);

echo "\n5) a visit with a tracking gap is skipped and reported\n";
[$f3] = LocationPoints::dayBounds('2026-10-03');
$dwell($f3 - 3600, $f3 + 7 * 3600, HOME);
$drive($f3 + 7 * 3600, HOME, OFFICE);
$dwell($f3 + 7 * 3600 + 1200, $f3 + 11 * 3600, OFFICE);
// …then nothing for 4 hours (phone died), and the next position is at home.
$dwell($f3 + 15 * 3600, $f3 + 26 * 3600, HOME);
$r = $clock->sync(1, '2026-10-03');
check('nothing written for the uncertain visit', $r['status'] === 'synced' && $r['inserted'] === 0, json_encode($r));
check('reported with place and the time it was seen arriving', ($r['uncertain'][0]['place'] ?? '') === 'Office' && ($r['uncertain'][0]['in'] ?? '') !== '', json_encode($r));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
