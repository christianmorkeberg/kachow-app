<?php

declare(strict_types=1);

/**
 * Pure unit tests for the DATA-GAP rules: Support\StayDetector (silence past max_gap_minutes is
 * "no data", never a stay, a back-dated departure or an invented trip) and Support\TrackingHealth
 * (when to push "tracking has stopped"). No DB. Run: php bin/location-gap-test.php
 *
 * Coordinates here are placeholders on round numbers — never real positions.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\StayDetector;
use App\Support\TrackingHealth;

$pass = 0; $fail = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; }
}

const HOME   = [55.0, 12.0];
const OFFICE = [55.1, 12.1];   // ~12.8 km from HOME
const AWAY   = [48.0, 7.0];    // ~850 km from HOME (a holiday abroad)

function ts(string $when): int
{
    return (int) strtotime($when . ' UTC');
}

/** @param list<array{0:string, 1:array{0:float,1:float}}> $rows [when, [lat, lon]] */
function pts(array $rows, int $acc = 5): array
{
    return array_map(static fn (array $r): array =>
        ['ts' => ts($r[0]), 'lat' => $r[1][0], 'lon' => $r[1][1], 'acc' => $acc], $rows);
}

// ---------------------------------------------------------------- the real report (11 Oct 2026)
// OwnTracks died abroad on the 5th and was only noticed on the 11th: a dense cluster abroad, five
// days of nothing, then a few scattered points back home. Before the gap rule this produced a
// 96-hour "stay" abroad and a 23-hour "drive" at exactly the back-dating speed.
echo "a five-day reporting hole invents nothing\n";
$real = pts([
    ['2026-10-05 14:10', AWAY], ['2026-10-05 14:18', AWAY], ['2026-10-05 14:26', AWAY],
    ['2026-10-05 14:34', AWAY], ['2026-10-05 14:42', AWAY], ['2026-10-05 14:50', AWAY],
    ['2026-10-10 14:12', HOME],
    ['2026-10-11 13:36', OFFICE], ['2026-10-11 14:32', OFFICE],
    ['2026-10-11 19:28', HOME],
]);
$r = StayDetector::detect($real, [], ts('2026-10-11 20:00'));
check('stays found', count($r['stays']), 3);
check('no trips guessed across the holes', count($r['trips']), 0);
check('three gaps reported', count($r['gaps']), 3);
check('the stay abroad is 40 min, not 96 h', (int) round(($r['stays'][0]['depart'] - $r['stays'][0]['arrive']) / 60), 40);
check('it is not left open', $r['stays'][0]['ongoing'], false);
check('the lone home point is no longer a 23 h stay', $r['stays'][1]['lat'], 55.1);
check('the big gap is 119 h', (int) round($r['gaps'][0]['minutes'] / 60), 119);
check('the last point is recent, so the final stay is ongoing', $r['stays'][2]['ongoing'], true);

// ---------------------------------------------------------------- an ordinary office day
echo "\nan ordinary day still behaves exactly as before\n";
$day = pts([
    ['2026-10-12 06:00', HOME], ['2026-10-12 07:10', HOME],   // 70 min of silence at home: fine
    ['2026-10-12 07:45', OFFICE], ['2026-10-12 08:30', OFFICE],
    ['2026-10-12 12:00', OFFICE],                              // quiet morning at the desk
    ['2026-10-12 15:40', OFFICE],
    ['2026-10-12 16:20', HOME],                                // drove home
]);
$r = StayDetector::detect($day, [], ts('2026-10-12 16:30'));
check('home, office, home', count($r['stays']), 3);
// 08:30→12:00 and 12:00→15:40 are silent, but they sit INSIDE the office stay: a parked phone,
// not a hole in the data, so they must not be reported as gaps.
check('quiet stretches inside a stay are not gaps', count($r['gaps']), 0);
check('two trips', count($r['trips']), 2);
check('the office day is one stay', (int) round(($r['stays'][1]['depart'] - $r['stays'][1]['arrive']) / 60), 494);
check('the commute home is a drive', $r['trips'][1]['mode'], 'vehicle');
// Departure still back-dated from the first point at home (12.8 km ⇒ ~21 min at 36 km/h).
check('departure back-dated past the last office point', (int) round(($r['stays'][1]['depart'] - ts('2026-10-12 15:40')) / 60), 19);

echo "\na long drive that IS tracked keeps its trip\n";
$drive = pts([
    ['2026-10-13 08:00', HOME], ['2026-10-13 08:15', HOME],
    // Reporting every 10 minutes on the way (what locatorInterval 300 gives while moving).
    ['2026-10-13 08:30', [55.05, 12.0]], ['2026-10-13 08:40', [55.10, 12.0]], ['2026-10-13 08:50', [55.15, 12.0]],
    ['2026-10-13 09:00', [55.20, 12.0]], ['2026-10-13 10:00', [55.20, 12.0]],
]);
$r = StayDetector::detect($drive, [], ts('2026-10-13 10:10'));
check('two stays', count($r['stays']), 2);
check('the trip survives', count($r['trips']), 1);
check('measured as a drive', $r['trips'][0]['mode'], 'vehicle');
check('no gap', count($r['gaps']), 0);

// ---------------------------------------------------------------- a tracker that just died
echo "\nsilence up to now is a gap, not an endless ongoing stay\n";
$dead = pts([
    ['2026-10-14 08:00', OFFICE], ['2026-10-14 08:30', OFFICE], ['2026-10-14 09:00', OFFICE],
]);
$r = StayDetector::detect($dead, [], ts('2026-10-14 18:00'));
check('the stay is closed at the last point', $r['stays'][0]['ongoing'], false);
check('it lasts 1 h, not 10', (int) round(($r['stays'][0]['depart'] - $r['stays'][0]['arrive']) / 60), 60);
check('one trailing gap', count($r['gaps']), 1);
check('marked ongoing', $r['gaps'][0]['ongoing'], true);
check('9 h of silence', (int) round($r['gaps'][0]['minutes'] / 60), 9);

echo "\nthe threshold is tunable: a huge max_gap restores the old guessing\n";
$r = StayDetector::detect($real, ['max_gap_minutes' => 20160], ts('2026-10-11 20:00'));
check('no gaps at a 14-day threshold', count($r['gaps']), 0);
check('and the fabricated trips are back', count($r['trips']) > 0, true);

echo "\nshort silence is still presence (iOS pauses updates when the phone is still)\n";
$night = pts([['2026-10-15 22:00', HOME], ['2026-10-15 23:00', HOME], ['2026-10-16 00:20', HOME]]);
$r = StayDetector::detect($night, [], ts('2026-10-16 00:30'));
check('one stay through the night', count($r['stays']), 1);
check('no gap at 80 min with a 90 min threshold', count($r['gaps']), 0);

// ---------------------------------------------------------------- TrackingHealth
echo "\nTrackingHealth: when to say \"tracking has stopped\"\n";
$tz   = new DateTimeZone('Europe/Copenhagen');
$at   = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);
$seen = static fn (string $s): int => (int) (new DateTimeImmutable($s, $tz))->getTimestamp();

check('never tracked → never alert', TrackingHealth::shouldAlert(null, $at('2026-10-16 14:00')), false);
check('fresh point → quiet', TrackingHealth::shouldAlert($seen('2026-10-16 13:30'), $at('2026-10-16 14:00')), false);
check('dead since yesterday evening, 08:30 → quiet (the night does not count)',
    TrackingHealth::shouldAlert($seen('2026-10-15 20:00'), $at('2026-10-16 08:30')), false);
check('…same tracker at 14:30 → alert',
    TrackingHealth::shouldAlert($seen('2026-10-15 20:00'), $at('2026-10-16 14:30')), true);
check('silent since 08:00, 13:59 → not yet', TrackingHealth::shouldAlert($seen('2026-10-16 08:00'), $at('2026-10-16 13:59')), false);
check('silent since 08:00, 14:00 → alert', TrackingHealth::shouldAlert($seen('2026-10-16 08:00'), $at('2026-10-16 14:00')), true);
check('the middle of the night stays silent', TrackingHealth::shouldAlert($seen('2026-10-15 10:00'), $at('2026-10-16 03:00')), false);
check('22:00 is past the window', TrackingHealth::shouldAlert($seen('2026-10-16 08:00'), $at('2026-10-16 22:00')), false);
check('silent for three weeks → stop nagging', TrackingHealth::shouldAlert($seen('2026-09-25 10:00'), $at('2026-10-16 14:00')), false);
check('a tighter threshold alerts sooner', TrackingHealth::shouldAlert($seen('2026-10-16 08:00'), $at('2026-10-16 11:00'), 3), true);

echo "\nthe threshold setting is clamped\n";
check('null → default', TrackingHealth::silentHours(null), 6);
check('empty → default', TrackingHealth::silentHours(''), 6);
check('0 → min', TrackingHealth::silentHours('0'), 2);
check('999 → max', TrackingHealth::silentHours('999'), 48);
check('12 → 12', TrackingHealth::silentHours('12'), 12);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
