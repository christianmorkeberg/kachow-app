<?php

declare(strict_types=1);

/**
 * Frozen location days: Support\DayFreeze (pure), Data\FrozenDays, and Timeline reading frozen
 * days once the raw points are purged. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/location-freeze-test.php
 *
 * Coordinates are placeholders on round numbers — never real positions.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\FrozenDays;
use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\Timeline;
use App\Data\UserSettings;
use App\Support\DayFreeze;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/location-freeze-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}
function ts(string $utc): int { return (int) strtotime($utc . ' UTC'); }

// ------------------------------------------------------------------ 1) pure helpers
echo "1) DayFreeze packs, stitches and cuts\n";
$det = [
    'stays' => [
        ['arrive' => 100, 'depart' => 500, 'lat' => 55.0, 'lon' => 12.0, 'ongoing' => false],
        ['arrive' => 700, 'depart' => 900, 'lat' => 55.1, 'lon' => 12.1, 'ongoing' => false],
        ['arrive' => 5000, 'depart' => 6000, 'lat' => 55.0, 'lon' => 12.0, 'ongoing' => false],
    ],
    'trips' => [
        ['start' => 500, 'end' => 700, 'km' => 12.8, 'mode' => 'vehicle', 'max_kmh' => 60, 'from_stay' => 0, 'to_stay' => 1],
        ['start' => 900, 'end' => 5000, 'km' => 12.8, 'mode' => 'vehicle', 'max_kmh' => 50, 'from_stay' => 1, 'to_stay' => 2],
    ],
    'gaps' => [['start' => 5100, 'end' => 5400, 'minutes' => 5, 'ongoing' => false]],
];
$p = DayFreeze::pack($det, 0, 1000);
check('keeps what overlaps the day, drops the rest', count($p['stays']) === 2 && count($p['trips']) === 2 && $p['gaps'] === []);
check('a stay is stored unclipped', $p['stays'][1]['depart'] === 900);
check('a trip carries the coordinates of both ends', $p['trips'][0]['a'] === [55.0, 12.0] && $p['trips'][0]['b'] === [55.1, 12.1]);
check('a trip that runs past the day is still kept whole', $p['trips'][1]['end'] === 5000);

$day1 = ['stays' => [['arrive' => 100, 'depart' => 1000, 'lat' => 55.0, 'lon' => 12.0]], 'trips' => [], 'gaps' => [['start' => 900, 'end' => 1200]]];
$day2 = ['stays' => [['arrive' => 160, 'depart' => 2000, 'lat' => 55.0, 'lon' => 12.0]], 'trips' => [], 'gaps' => [['start' => 900, 'end' => 1300]]];
$m = DayFreeze::merge([$day2, $day1]);
check('a stay seen from two days becomes one, covering both', count($m['stays']) === 1 && $m['stays'][0]['arrive'] === 100 && $m['stays'][0]['depart'] === 2000, json_encode($m['stays']));
check('so does a gap', count($m['gaps']) === 1 && $m['gaps'][0]['end'] === 1300);
check('separate stays stay separate', count(DayFreeze::merge([['stays' => [['arrive' => 1, 'depart' => 5], ['arrive' => 9, 'depart' => 20]]]])['stays']) === 2);
check('nothing to merge is fine', DayFreeze::merge([]) === ['stays' => [], 'trips' => [], 'gaps' => []]);
$c = DayFreeze::cutAt(['stays' => [['arrive' => 1, 'depart' => 50], ['arrive' => 60, 'depart' => 150]], 'trips' => [['start' => 50, 'end' => 60]], 'gaps' => []], 100);
check('cutAt drops what reaches past the seam', count($c['stays']) === 1 && count($c['trips']) === 1);

// ------------------------------------------------------------------ 2) storage + Timeline
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE location_points (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device TEXT, recorded_at TEXT, lat REAL, lon REAL,
    acc INT, alt INT, vel INT, cog INT, batt INT, conn TEXT, trig TEXT, received_at TEXT)');
$db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, type TEXT, shape TEXT, lat REAL, lon REAL, radius_m INT, polygon TEXT)');
$db->exec('CREATE TABLE user_settings (user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec('CREATE TABLE frozen_location_days (user_id INT, day TEXT, data TEXT, frozen_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, day))');

echo "\n2) FrozenDays stores one payload per day and replaces it\n";
$store = new FrozenDays($db);
$store->save(1, '2026-10-01', ['stays' => [1]]);
$store->save(1, '2026-10-01', ['stays' => [1, 2]]);
check('one row after saving twice', (int) $db->query('SELECT COUNT(*) FROM frozen_location_days')->fetchColumn() === 1);
check('the newer payload wins', count($store->between(1, '2026-10-01', '2026-10-01')['2026-10-01']['stays']) === 2);
check('has() knows', $store->has(1, '2026-10-01') && !$store->has(1, '2026-10-02') && !$store->has(2, '2026-10-01'));
$db->exec('DELETE FROM frozen_location_days');

// Three days of a simple life: home overnight, office 06:50–14:00 UTC (08:50–16:00 local), back home.
const HOME   = [55.0, 12.0];
const OFFICE = [55.1, 12.1];
$points = new LocationPoints($db);
$n = 0;
$put = static function (int $t, array $at) use ($db, &$n): void {
    $n++;
    $db->prepare('INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc) VALUES (1, "t", :at, :lat, :lon, 5)')
        ->execute([':at' => gmdate('Y-m-d H:i:s', $t), ':lat' => $at[0], ':lon' => $at[1]]);
};
$dwell = static function (int $from, int $to, array $at) use ($put): void {
    for ($t = $from; $t < $to; $t += 600) { $put($t, $at); }
};
$drive = static function (int $from, array $a, array $b) use ($put): void {
    for ($k = 1; $k <= 6; $k++) {
        $f = $k / 7;
        $put($from + $k * 180, [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f]);
    }
};
$t0 = ts('2026-10-01 00:00');
for ($d = 0; $d < 5; $d++) {
    $base = $t0 + $d * 86400;
    $dwell($base, $base + 6 * 3600 + 1800, HOME);
    $drive($base + 6 * 3600 + 1800, HOME, OFFICE);
    $dwell($base + 6 * 3600 + 3000, $base + 14 * 3600, OFFICE);
    $drive($base + 14 * 3600, OFFICE, HOME);
    $dwell($base + 14 * 3600 + 1500, $base + 86400, HOME);
}
$db->exec("INSERT INTO places (user_id, name, type, shape, lat, lon, radius_m) VALUES (1, 'Home', 'home', 'circle', 55.0, 12.0, 100)");
$timeline = new Timeline($points, new Places($db), new UserSettings($db), $store);
[$d2From, $d2To] = LocationPoints::dayBounds('2026-10-02');

echo "\n3) a day computed live, frozen, then purged reads back the same\n";
$live = $timeline->analyse(1, $d2From, $d2To);
check('the live day has stays and trips', count($live['stays']) >= 3 && count($live['trips']) >= 2, count($live['stays']) . ' stays');
foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
    $timeline->freezeDay(1, $date);
}
check('three days frozen', (int) $db->query('SELECT COUNT(*) FROM frozen_location_days')->fetchColumn() === 3);
// "Purge": raw points before 3 Oct 08:00 UTC are gone, as location_points does after 60 days.
$db->exec("DELETE FROM location_points WHERE recorded_at < '2026-10-03 08:00:00'");
check('the purge removed the raw points of 2 Oct', $points->between(1, $d2From, $d2To) === []);
$frozen = $timeline->analyse(1, $d2From, $d2To);
$key = static fn (array $r): array => array_map(static fn (array $s): array => [$s['arrive'], $s['depart'], $s['place']], $r['stays']);
check('same stays, same times, same places', $key($frozen) === $key($live), json_encode([$key($frozen), $key($live)]));
$tripKey = static fn (array $r): array => array_map(static fn (array $t): array => [$t['start'], $t['end'], $t['km'], $t['mode'], $t['from'], $t['to']], $r['trips']);
check('same trips', $tripKey($frozen) === $tripKey($live));
check('a frozen stay is never "ongoing"', !in_array(true, array_column($frozen['stays'], 'ongoing'), true));

echo "\n4) places are matched when read, so a later rename still reaches old days\n";
$db->exec("UPDATE places SET name = 'Hjem'");
$renamed = $timeline->analyse(1, $d2From, $d2To);
check('old day shows the new name', in_array('Hjem', array_column($renamed['stays'], 'place'), true) && !in_array('Home', array_column($renamed['stays'], 'place'), true));
$dayView = $timeline->day(1, '2026-10-02');
check('the day card and stats work from frozen data', count($dayView['card']['timeline']) >= 3);

echo "\n5) a range across the seam has no duplicates and no holes\n";
[$wFrom] = LocationPoints::dayBounds('2026-10-01');
[, $wTo] = LocationPoints::dayBounds('2026-10-03');
$wide = $timeline->analyse(1, $wFrom, $wTo);
$ok = true;
for ($i = 1, $c = count($wide['stays']); $i < $c; $i++) {
    if ($wide['stays'][$i]['arrive'] < $wide['stays'][$i - 1]['depart']) { $ok = false; }
}
check('stays are in order and never overlap', $ok, json_encode(array_map(fn ($s) => [gmdate('d H:i', $s['arrive']), gmdate('d H:i', $s['depart'])], $wide['stays'])));
check('three days of office visits (frozen 1–2 Oct, live 3 Oct)', count(array_filter($wide['stays'], fn ($s) => $s['place'] === null)) >= 3);
$offices = array_filter($wide['stays'], fn ($s) => $s['place'] === null);
check("each office visit once", count($offices) === 3, json_encode(array_map(fn ($s) => [gmdate("d H:i", $s["arrive"]), gmdate("d H:i", $s["depart"]), $s["place"]], array_values($wide["stays"]))));

echo "\n6) before the migration runs, old days are simply empty — not an error\n";
$bare = new PDO('sqlite::memory:');
$bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$bare->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$bare->exec('CREATE TABLE location_points (id INTEGER PRIMARY KEY, user_id INT, device TEXT, recorded_at TEXT, lat REAL, lon REAL, acc INT, vel INT, batt INT)');
$bare->exec('CREATE TABLE places (id INTEGER PRIMARY KEY, user_id INT, name TEXT, type TEXT, shape TEXT, lat REAL, lon REAL, radius_m INT, polygon TEXT)');
$bare->exec('CREATE TABLE user_settings (user_id INT, setting_key TEXT, setting_value TEXT)');
$noTable = new Timeline(new LocationPoints($bare), new Places($bare), new UserSettings($bare), new FrozenDays($bare));
$r = $noTable->analyse(1, $d2From, $d2To);
check('no table, no points: empty result', $r['stays'] === [] && $r['trips'] === []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
