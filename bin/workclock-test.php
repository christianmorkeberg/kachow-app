<?php

declare(strict_types=1);

/**
 * Pure unit tests for Support\WorkClock::sessions — the phase-4 work-clock rules (dwell,
 * same-day bridging with a cap, A→B→A, midnight/ongoing). No DB. Run: php bin/workclock-test.php
 */

require __DIR__ . '/../src/Support/WorkClock.php';

use App\Support\WorkClock;

$P = WorkClock::DEFAULTS; // min_dwell 10, max_bridge 3h
$DAY   = strtotime('2026-09-29 00:00:00 UTC');
$END   = $DAY + 86400;
$h = fn (int $hr, int $mn = 0): int => $DAY + $hr * 3600 + $mn * 60;

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . json_encode($got) . ", want " . json_encode($want) . "\n"; }
}
/** stay helper */
function st(?int $id, int $arrive, int $depart, bool $ongoing = false): array
{
    return ['place_id' => $id, 'place' => $id !== null ? "P$id" : null, 'arrive' => $arrive, 'depart' => $depart, 'ongoing' => $ongoing];
}
$run = fn (array $stays): array => WorkClock::sessions($stays, $DAY, $END, $P);

echo "1) drive-by (<10 min) never opens a session\n";
$s = $run([st(1, $h(9, 0), $h(9, 5))]);
check('no sessions', count($s), 0);

echo "2) a single work stay = one session\n";
$s = $run([st(1, $h(9), $h(12))]);
check('one session', count($s), 1);
check('180 min', $s[0]['minutes'], 180);

echo "3) same-day return to SAME place within 3h bridges into one session\n";
$s = $run([st(1, $h(9), $h(12)), st(1, $h(13), $h(17))]);
check('one bridged session', count($s), 1);
check('09:00–17:00 = 480 min', $s[0]['minutes'], 480);

echo "4) gap over the 3h cap → two sessions\n";
$s = $run([st(1, $h(9), $h(12)), st(1, $h(16), $h(18))]);
check('two sessions', count($s), 2);

echo "5) A→B→A is NOT bridged (three sessions)\n";
$s = $run([st(1, $h(9), $h(11)), st(2, $h(11, 30), $h(12)), st(1, $h(12, 30), $h(17))]);
check('three sessions', count($s), 3);
check('first is P1', $s[0]['place'], 'P1');
check('middle is P2', $s[1]['place'], 'P2');
check('last is P1 again', $s[2]['place'], 'P1');

echo "6) a short return (drive-by) does not extend a session\n";
$s = $run([st(1, $h(9), $h(12)), st(1, $h(13), $h(13, 5))]);
check('one session, unextended', count($s), 1);
check('still 180 min', $s[0]['minutes'], 180);

echo "7) a stay crossing midnight is clipped and NOT ongoing\n";
$s = $run([st(1, $h(22), $END + 4 * 3600, true)]);
check('one session', count($s), 1);
check('120 min to midnight', $s[0]['minutes'], 120);
check('not ongoing (cut by midnight)', $s[0]['ongoing'], false);

echo "8) today's open stay stays ongoing\n";
$s = $run([st(1, $h(9), $h(14), true)]);
check('ongoing', $s[0]['ongoing'], true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
