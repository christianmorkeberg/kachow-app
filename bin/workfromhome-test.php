<?php

declare(strict_types=1);

/**
 * Pure unit tests for Support\WorkFromHome::shouldPrompt — the gated 09:00 "working from home?"
 * rule (calendar-scheduled + weekday + 9am + not clocked in + no work stay yet + ongoing home
 * stay). No DB. Run: php bin/workfromhome-test.php
 */

require __DIR__ . '/../src/Support/WorkFromHome.php';

use App\Support\WorkFromHome;

$pass = 0; $fail = 0;
function check(string $label, bool $got, bool $want): void
{
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; }
}

$tz     = new DateTimeZone('Europe/Copenhagen');
$mon9   = new DateTimeImmutable('2026-09-28 09:15', $tz); // Monday 09:xx
$mon8   = new DateTimeImmutable('2026-09-28 08:15', $tz); // Monday 08:xx (too early)
$sat9   = new DateTimeImmutable('2026-09-26 09:15', $tz); // Saturday
$homeOngoing = [['type' => 'home', 'ongoing' => true]];
$homeClosed  = [['type' => 'home', 'ongoing' => false]];
$atWork      = [['type' => 'work', 'ongoing' => false], ['type' => 'home', 'ongoing' => true]];

echo "gate: no work scheduled today → never prompt\n";
check('home + 9am but not scheduled', WorkFromHome::shouldPrompt($homeOngoing, false, $mon9, false), false);

echo "happy path: scheduled + weekday 9am + home + not clocked in\n";
check('prompts', WorkFromHome::shouldPrompt($homeOngoing, false, $mon9, true), true);

echo "already clocked in → no prompt\n";
check('clocked in', WorkFromHome::shouldPrompt($homeOngoing, true, $mon9, true), false);

echo "already been at a work place today → no prompt\n";
check('work stay today', WorkFromHome::shouldPrompt($atWork, false, $mon9, true), false);

echo "not currently at home (no ongoing home stay) → no prompt\n";
check('home stay closed', WorkFromHome::shouldPrompt($homeClosed, false, $mon9, true), false);

echo "wrong time / weekend → no prompt\n";
check('08:xx too early', WorkFromHome::shouldPrompt($homeOngoing, false, $mon8, true), false);
check('Saturday', WorkFromHome::shouldPrompt($homeOngoing, false, $sat9, true), false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
