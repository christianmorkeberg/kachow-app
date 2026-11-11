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

echo "\n3) day / 3-day / week views of the same card\n";
check('viewRange: a day, three days, the Monday–Sunday week, the month grid', Calendar::viewRange('day', '2026-10-14') === ['2026-10-14', '2026-10-14'] && Calendar::viewRange('3day', '2026-10-14') === ['2026-10-14', '2026-10-16']
    && Calendar::viewRange('week', '2026-10-14') === ['2026-10-12', '2026-10-18'] && Calendar::viewRange('month', '2026-10-14') === ['2026-09-28', '2026-11-01'], json_encode(Calendar::viewRange('week', '2026-10-14')));
[$f, $t] = Calendar::rangeUtc('2026-10-12', '2026-10-18');
check('rangeUtc covers the local days (CEST = UTC+2)', $f === '2026-10-11T22:00:00Z' && $t === '2026-10-18T22:00:00Z', "$f $t");
$w = $cal->buildViewCard($events, 'week', '2026-10-14');
$wd = (array) $w['days'];
check('week card: kind, view, range, anchors a week apart', $w['kind'] === 'calendar_month' && $w['view'] === 'week' && $w['range_from'] === '2026-10-12' && $w['range_to'] === '2026-10-18' && $w['prev'] === '2026-10-07' && $w['next'] === '2026-10-21' && $w['title'] === '12 Oct – 18 Oct 2026', json_encode([$w['range_from'], $w['prev'], $w['title']]));
check('week card: only that week\'s events (the 3-day holiday starts on the 19th, so it is out)', isset($wd['2026-10-14']) && !isset($wd['2026-10-19']) && !isset($wd['2026-09-29']) && count($wd['2026-10-14']) === 2);
$d1 = $cal->buildViewCard($events, 'day', '2026-10-14');
check('day card: one day, steps of one day', array_keys((array) $d1['days']) === ['2026-10-14'] && $d1['prev'] === '2026-10-13' && $d1['next'] === '2026-10-15' && $d1['title'] === 'Wed 14 Oct 2026');
$d3 = $cal->buildViewCard($events, '3day', '2026-10-19');
check('3-day card: the multi-day event shows on each day, "cont." after the first', isset($d3['days']['2026-10-19'], $d3['days']['2026-10-20'], $d3['days']['2026-10-21']) && $d3['next'] === '2026-10-22');
check('an empty range renders (the page shows "nothing scheduled")', (array) $cal->buildViewCard([], 'week', '2026-12-01')['days'] === []);
check('month view = the month card (with a view and an anchor)', ($m = $cal->buildViewCard($events, 'month', '2026-10-14'))['view'] === 'month' && $m['month'] === '2026-10' && $m['anchor'] === '2026-10-14');
check('an unknown view falls back to the month', $cal->buildViewCard($events, 'fortnight', '2026-10-14')['view'] === 'month');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
