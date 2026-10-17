<?php

declare(strict_types=1);

/**
 * Month-grid card: Calendar::monthGrid / monthRangeUtc / buildMonthCard (pure — no Google call).
 *   php bin/calendar-month-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Calendar;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) the grid's days\n";
check('October 2026 runs Mon 28 Sep – Sun 1 Nov', Calendar::monthGrid('2026-10') === ['2026-09-28', '2026-11-01'], json_encode(Calendar::monthGrid('2026-10')));
check('February 2027 starts on a Monday: no leading days', Calendar::monthGrid('2027-02')[0] === '2027-02-01');
check('November 2026 ends on Sun 6 Dec', Calendar::monthGrid('2026-11')[1] === '2026-12-06');
[$from, $to] = Calendar::monthRangeUtc('2026-10');
check('fetch range in UTC, local midnight to local midnight', $from === '2026-09-27T22:00:00Z' && $to === '2026-11-01T23:00:00Z', "$from $to");

echo "\n2) building the card\n";
$cal = (new ReflectionClass(Calendar::class))->newInstanceWithoutConstructor();   // no Google needed
$events = [
    ['summary' => 'Dentist', 'start' => '2026-10-14T09:00:00+02:00', 'end' => '2026-10-14T10:00:00+02:00', 'allDay' => false, 'calendar' => 'Personal'],
    ['summary' => 'Holiday', 'start' => '2026-10-19', 'end' => '2026-10-22', 'allDay' => true, 'calendar' => 'Family'],
    ['summary' => 'Week 43', 'start' => '2026-10-19', 'end' => '2026-10-20', 'allDay' => true, 'calendar' => 'Weeks'],
    ['summary' => 'Late gig', 'start' => '2026-10-24T21:00:00+02:00', 'end' => '2026-10-25T00:00:00+02:00', 'allDay' => false],
    ['summary' => 'Before', 'start' => '2026-09-29T08:00:00+02:00', 'end' => '2026-09-29T09:00:00+02:00', 'allDay' => false],
    ['summary' => 'Way before', 'start' => '2026-09-01T08:00:00+02:00', 'end' => '2026-09-01T09:00:00+02:00', 'allDay' => false],
    ['summary' => 'Standup', 'start' => '2026-10-14T08:00:00+02:00', 'end' => '2026-10-14T08:15:00+02:00', 'allDay' => false],
];
$card = $cal->buildMonthCard($events, '2026-10');
$days = (array) $card['days'];
check('kind, month and paging', $card['kind'] === 'calendar_month' && $card['month'] === '2026-10' && $card['prev'] === '2026-09' && $card['next'] === '2026-11');
check('a timed event with its time range', ($days['2026-10-14'][1]['time'] ?? '') === '09:00–10:00', json_encode($days['2026-10-14'] ?? null));
check('a day sorted by time', ($days['2026-10-14'][0]['summary'] ?? '') === 'Standup');
check('a 3-day all-day event shows on each of its days (end date exclusive)', isset($days['2026-10-19'], $days['2026-10-20'], $days['2026-10-21']) && !isset($days['2026-10-22']));
check('the week-number marker is dropped', count($days['2026-10-19']) === 1 && $days['2026-10-19'][0]['summary'] === 'Holiday');
check('an event ending at midnight stays on its own day', isset($days['2026-10-24']) && !isset($days['2026-10-25']));
check('a leading day of the grid (late Sept) has its events', isset($days['2026-09-29']));
check('days outside the grid are left out', !isset($days['2026-09-01']));
check('an empty month still renders', (array) $cal->buildMonthCard([], '2026-12')['days'] === []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
