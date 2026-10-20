<?php

declare(strict_types=1);

/**
 * log_work_event must not start a second session while one is open (report #46): the collision rule, pure.
 *   php bin/work-clock-guard-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\HomeNow;
use App\Tools\LogWorkEvent;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$dtu = ['place' => 'DTU', 'in_id' => 1, 'occurred_at' => '2026-10-19 05:49:00'];
$dsb = ['place' => 'DSB', 'in_id' => 2, 'occurred_at' => '2026-10-19 06:10:00'];

check('no open session → no conflict', LogWorkEvent::openConflict([], null) === null && LogWorkEvent::openConflict([], 'DTU') === null);
check('a placeless clock-in next to an open session collides (the report #46 case)', LogWorkEvent::openConflict([$dtu], null) === $dtu && LogWorkEvent::openConflict([$dtu], '  ') === $dtu);
check('the same workplace collides, case-insensitively', LogWorkEvent::openConflict([$dtu], 'dtu') === $dtu);
check('a different named workplace is allowed', LogWorkEvent::openConflict([$dtu], 'DSB') === null);
check('with several open, the named one is found', LogWorkEvent::openConflict([$dtu, $dsb], 'dsb') === $dsb);
check('an open session with no workplace collides with a placeless in only', LogWorkEvent::openConflict([['place' => null, 'in_id' => 3, 'occurred_at' => 'x']], null) !== null
    && LogWorkEvent::openConflict([['place' => null, 'in_id' => 3, 'occurred_at' => 'x']], 'DTU') === null);

echo "work from home, detected from the latest point\n";
$home  = [['type' => 'home', 'shape' => 'circle', 'lat' => 55.0, 'lon' => 12.0, 'radius_m' => 85, 'polygon' => []],
          ['type' => 'work', 'shape' => 'circle', 'lat' => 55.5, 'lon' => 12.5, 'radius_m' => 200, 'polygon' => []]];
$now   = strtotime('2026-10-20 08:11:00 UTC');
$pt    = static fn (string $at, float $lat, float $lon, ?int $acc = 10): array => ['recorded_at' => $at, 'lat' => $lat, 'lon' => $lon, 'acc' => $acc];
check('a fresh accurate point inside Home → at home', HomeNow::atHome($pt('2026-10-20 08:05:00', 55.0002, 12.0002), $home, $now));
check('inside the work place → not at home', !HomeNow::atHome($pt('2026-10-20 08:05:00', 55.5, 12.5), $home, $now));
check('an old point does not count', !HomeNow::atHome($pt('2026-10-20 07:30:00', 55.0, 12.0), $home, $now));
check('a sloppy point does not count', !HomeNow::atHome($pt('2026-10-20 08:05:00', 55.0, 12.0, 400), $home, $now));
check('no point → not at home', !HomeNow::atHome(null, $home, $now));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
