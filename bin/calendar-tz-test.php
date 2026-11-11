<?php

declare(strict_types=1);

/** Calendar times are shown in the user's zone whatever zone the source calendar is set to (report #76/#77). No network: php bin/calendar-tz-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Calendar;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ({$d})") . "\n"; }

echo "1) localise: same instant, local offset\n";
// Astrid's sauna: Tuesday 17:00-19:00 in Copenhagen, returned by Google for a calendar set to a +08:00 zone as Wednesday 00:00-02:00
check('a +08:00 time becomes Tuesday 17:00 +01:00', Calendar::localise('2026-11-11T00:00:00+08:00') === '2026-11-10T17:00:00+01:00', (string) Calendar::localise('2026-11-11T00:00:00+08:00'));
check('UTC (Z) is shifted too', Calendar::localise('2026-11-10T16:00:00Z') === '2026-11-10T17:00:00+01:00');
check('summer time gets +02:00', Calendar::localise('2026-07-10T12:00:00Z') === '2026-07-10T14:00:00+02:00');
check('already local stays as it is', Calendar::localise('2026-11-10T17:00:00+01:00') === '2026-11-10T17:00:00+01:00');
check('an all-day date is untouched', Calendar::localise('2026-11-10') === '2026-11-10' && Calendar::localise(null) === null);
check('rubbish is returned unchanged, never throws', Calendar::localise('not a time at all!') === 'not a time at all!');
check('a US zone crossing midnight lands on the right day', Calendar::localise('2026-11-09T18:00:00-06:00') === '2026-11-10T01:00:00+01:00');

echo "2) the card shows the user's clock\n";
$cal = (new ReflectionClass(Calendar::class))->newInstanceWithoutConstructor();
$events = [[
    'id' => 'e1', 'summary' => 'Sauna', 'calendar' => 'Venner', 'location' => null, 'allDay' => false,
    'start' => Calendar::localise('2026-11-11T00:00:00+08:00'), 'end' => Calendar::localise('2026-11-11T02:00:00+08:00'),
]];
$card = $cal->buildViewCard($events, 'day', '2026-11-10');
check('Tuesday 17:00–19:00, not Wednesday 00:00–02:00', isset($card['days']['2026-11-10']) && $card['days']['2026-11-10'][0]['time'] === '17:00–19:00', json_encode($card['days']));
$wrong = $cal->buildViewCard([['summary' => 'Sauna', 'allDay' => false, 'start' => '2026-11-11T00:00:00+08:00', 'end' => '2026-11-11T02:00:00+08:00']], 'day', '2026-11-11');
check('(what the raw string gave before: the bug)', isset($wrong['days']['2026-11-11']) && $wrong['days']['2026-11-11'][0]['time'] === '00:00–02:00');

echo "3) offsets\n";
check('Z and ±hh:mm / ±hhmm count as an offset', Calendar::hasOffset('2026-12-26T12:00:00Z') && Calendar::hasOffset('2026-12-26T12:00:00+01:00') && Calendar::hasOffset('2026-12-26T12:00:00-0600'));
check('a plain wall-clock time has none (it is read in Europe/Copenhagen)', !Calendar::hasOffset('2026-12-26T12:00:00') && !Calendar::hasOffset('2026-12-26 12:00'));
check('the display zone is Copenhagen', Calendar::DISPLAY_TZ === 'Europe/Copenhagen');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
