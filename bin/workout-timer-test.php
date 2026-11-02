<?php

declare(strict_types=1);

/**
 * open_workout_timer: which card it opens and what it fills in (clamped), and the timer as a start card. Pure, no database:
 *   php bin/workout-timer-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;
use App\Tools\OpenWorkoutTimer;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$t = new OpenWorkoutTimer();
$card = static fn (array $a): array => $t->execute($a, 1)['_render'];

echo "1) EMOM\n";
$c = $card(['mode' => 'emom', 'work_seconds' => 40, 'rest_seconds' => 20, 'rounds' => 12]);
check('work, rest and rounds are filled in', $c === ['kind' => 'workout_timer', 'mode' => 'emom', 'work' => 40, 'rest' => 20, 'rounds' => 12], json_encode($c));
$c = $card(['mode' => 'emom', 'work_seconds' => 60, 'rest_seconds' => 0, 'total_minutes' => 12]);
check('total time instead of rounds', ($c['total_minutes'] ?? null) === 12 && !isset($c['rounds']) && $c['rest'] === 0, json_encode($c));
$c = $card(['mode' => 'emom', 'rounds' => 8, 'total_minutes' => 20]);
check('rounds win over total time when both are given', ($c['rounds'] ?? null) === 8 && !isset($c['total_minutes']));
$c = $card(['work_seconds' => 5000, 'rest_seconds' => -4, 'rounds' => 9999]);
check('values are clamped (and the mode follows the arguments)', $c['mode'] === 'emom' && $c['work'] === 900 && $c['rest'] === 0 && $c['rounds'] === 200, json_encode($c));
check('a bare "start an EMOM" opens an empty EMOM card', $card([]) === ['kind' => 'workout_timer', 'mode' => 'emom']);

echo "\n2) timer and stopwatch\n";
check('countdown', $card(['mode' => 'timer', 'minutes' => 10]) === ['kind' => 'workout_timer', 'mode' => 'timer', 'minutes' => 10]);
check('countdown by minutes/seconds alone', ($card(['minutes' => 1, 'seconds' => 30])['mode'] ?? '') === 'timer');
check('seconds are capped at 59', $card(['mode' => 'timer', 'seconds' => 90])['seconds'] === 59);
check('stopwatch carries no settings', $card(['mode' => 'stopwatch', 'minutes' => 5]) === ['kind' => 'workout_timer', 'mode' => 'stopwatch']);

echo "\n3) what the model is told\n";
$r = $t->execute(['mode' => 'timer', 'minutes' => 5], 1);
check('says the user must press Start', $r['opened'] === true && str_contains($r['note'], 'Start'));

echo "\n4) as a start card\n";
check('timer is a start-card key and survives normalizing', in_array('timer', UserSettings::HOME_CARD_KEYS, true) && UserSettings::normalizeHomeCards('timer,work_hours') === 'timer,work_hours');

echo "\n" . ($fail === 0 ? "OK — {$pass} checks passed.\n" : "FAILED — {$fail} of " . ($pass + $fail) . ".\n");
exit($fail === 0 ? 0 : 1);
