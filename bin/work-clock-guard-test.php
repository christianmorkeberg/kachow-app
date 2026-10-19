<?php

declare(strict_types=1);

/**
 * log_work_event must not start a second session while one is open (report #46): the collision rule, pure.
 *   php bin/work-clock-guard-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
