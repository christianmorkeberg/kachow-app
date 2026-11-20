<?php

declare(strict_types=1);

/** Work schedule, days off, balance, holiday suggestions. In-memory SQLite. php -d extension=pdo_sqlite bin/work-schedule-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\WorkEvents;
use App\Data\WorkSchedule;
use App\Support\DanishHolidays;
use App\Support\LocalTime;
use App\Tools\AddWorkAbsence;
use App\Tools\DeleteWorkAbsence;
use App\Tools\GetWorkBalance;
use App\Tools\SetWorkSchedule;
use App\Tools\SuggestWorkHolidays;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT, source TEXT DEFAULT "manual", note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$empty = new WorkSchedule($db, new WorkEvents($db));
check('before the migration: unavailable, balance says not configured', !$empty->available() && $empty->balance(1) === ['configured' => false] && $empty->schedules(1) === []);
$db->exec('CREATE TABLE work_schedule (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, effective_from TEXT, place TEXT, hours TEXT, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE work_absences (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, absence_date TEXT, kind TEXT, hours REAL, place TEXT, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$events = new WorkEvents($db);
$ws     = new WorkSchedule($db, $events);
$ins    = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (1, ?, ?, ?)');
$punch  = static function (string $day, string $from, string $to, string $place = 'DSB') use ($ins): void {
    $ins->execute(['in', LocalTime::toUtc("$day $from"), $place]);
    $ins->execute(['out', LocalTime::toUtc("$day $to"), $place]);
};

echo "1) not configured yet\n";
check('no schedule → configured false, with a hint', (new GetWorkBalance($ws))->execute([], 1)['configured'] === false);

echo "2) the schedule is data the user gives\n";
$set = new SetWorkSchedule($ws);
$r = $set->execute(['from' => '2026-11-02', 'hours_per_week' => 37, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']], 1);
check('37 h over mon–fri = 7.4 h a day', $r['saved'] === true && $r['hours'][1] === 7.4 && $r['per_week'] === 37.0, json_encode($r));
check('bad input is refused, not guessed', $set->execute(['from' => 'soon', 'hours_per_week' => 37, 'days' => ['mon']], 1)['saved'] === false && $set->execute(['from' => '2026-11-02'], 1)['saved'] === false);
$sch = $ws->schedules(1);
check('target minutes: Monday 444, Saturday 0, before the start 0', WorkSchedule::targetMinutes($sch, '2026-11-02') === 444 && WorkSchedule::targetMinutes($sch, '2026-11-07') === 0 && WorkSchedule::targetMinutes($sch, '2026-10-30') === 0);

echo "3) days off credit the day's hours; weekends are skipped\n";
$add = new AddWorkAbsence($ws);
$a = $add->execute(['from' => '2026-11-04', 'to' => '2026-11-08', 'kind' => 'holiday'], 1);   // Wed–Sun
check('Wed, Thu, Fri marked; Sat and Sun skipped (no scheduled hours)', $a['days_marked'] === 3 && count($a['skipped_no_scheduled_hours']) === 2, json_encode($a));

echo "4) the balance: worked + credited − target, opening balance 0, through today\n";
$punch('2026-11-02', '08:00', '16:00');            // Mon 8h
$punch('2026-11-03', '07:00', '14:24');            // Tue 7h24
// Wed–Fri holiday (3 × 444 credited)
$b = $ws->balance(1, null, null, '2026-11-08');   // "today" is the Sunday (no target): through today = the whole week
// worked 480+444 = 924; credited 3*444 = 1332; target 5*444 = 2220 → +36
check('balance = +36 min', $b['totals']['balance'] === 36 && $b['totals']['worked'] === 924 && $b['totals']['credited'] === 1332 && $b['totals']['target'] === 2220, json_encode($b['totals']));
check('counted from the schedule start through today', $b['from'] === '2026-11-02' && $b['through'] === '2026-11-08');
$t = (new GetWorkBalance($ws))->execute([], 1);
check('the tool answers with a signed label and the schedule', $t['configured'] === true && isset($t['balances'][0]['balance']) && $t['schedule'][0]['per_week'] === 37.0);
check('signed labels', GetWorkBalance::signed(36) === '+0h 36m' && GetWorkBalance::signed(-492) === '−8h 12m');

echo "5) a part-day absence credits its hours; removing days off changes the balance\n";
$add->execute(['dates' => ['2026-11-03'], 'kind' => 'sick', 'hours' => 1], 1);
$b2 = $ws->balance(1, null, null, '2026-11-08');
check('Tuesday: worked 444 + 60 credited', $b2['totals']['credited'] === 1332 + 60 && $b2['totals']['balance'] === 96, json_encode($b2['totals']));
$del = (new DeleteWorkAbsence($ws))->execute(['from' => '2026-11-04', 'to' => '2026-11-06'], 1);
check('delete removes the days and says which', $del['days_removed'] === 3 && count($ws->absences(1)) === 1);
$b3 = $ws->balance(1, null, null, '2026-11-08');
check('without the holiday the balance is behind', $b3['totals']['balance'] === 96 - 1332, json_encode($b3['totals']));

echo "6) a later schedule replaces from its date; a session over midnight is split\n";
$set->execute(['from' => '2026-11-09', 'daily' => ['mon' => 4, 'tue' => 4]], 1);
$sch = $ws->schedules(1);
check('from the new date only Mon/Tue count, 4 h each', WorkSchedule::targetMinutes($sch, '2026-11-09') === 240 && WorkSchedule::targetMinutes($sch, '2026-11-11') === 0 && WorkSchedule::targetMinutes($sch, '2026-11-06') === 444);
$punch('2026-11-12', '22:00', '23:59');
$ins->execute(['in', LocalTime::toUtc('2026-11-13 22:00'), 'DTU']);
$ins->execute(['out', LocalTime::toUtc('2026-11-14 02:00'), 'DTU']);
$m = $events->minutesPerDay(1, '2026-11-13', '2026-11-14');
check('22:00–02:00 counts 2 h on each local day', ($m['2026-11-13'] ?? 0) === 120 && ($m['2026-11-14'] ?? 0) === 120, json_encode($m));
check('one workplace only', $events->minutesPerDay(1, '2026-11-02', '2026-11-03', 'DSB') === ['2026-11-02' => 480, '2026-11-03' => 444] && $events->minutesPerDay(1, '2026-11-02', '2026-11-03', 'DTU') === []);

echo "7) public holidays are SUGGESTIONS\n";
$h = array_column(DanishHolidays::forYear(2026), 'name', 'date');
check('Easter 2026: Good Friday Apr 3, Easter Monday Apr 6, Ascension May 14, Whit Monday May 25', ($h['2026-04-03'] ?? '') === 'Good Friday' && ($h['2026-04-06'] ?? '') === 'Easter Monday' && ($h['2026-05-14'] ?? '') === 'Ascension Day' && ($h['2026-05-25'] ?? '') === 'Whit Monday');
check('Great Prayer Day only before 2024', in_array('Great Prayer Day', array_column(DanishHolidays::forYear(2023), 'name'), true) && !in_array('Great Prayer Day', array_column(DanishHolidays::forYear(2026), 'name'), true));
$ws2 = new WorkSchedule($db, $events);
$set->execute(['from' => '2026-12-01', 'hours_per_week' => 37, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']], 1);
$sg = (new SuggestWorkHolidays($ws2))->execute(['from' => '2026-12-01', 'to' => '2026-12-31'], 1);
$pub = array_column($sg['public'], 'date');
check('Dec 25 (Fri) is suggested, Dec 26 (Sat) is not — no hours that day', in_array('2026-12-25', $pub, true) && !in_array('2026-12-26', $pub, true), json_encode($pub));
check('Christmas Eve (Thu) and New Year\'s Eve (Thu) are "often off", listed separately', array_column($sg['often_off'], 'date') === ['2026-12-24', '2026-12-31'], json_encode($sg['often_off']));
check('nothing was marked by suggesting', count(array_filter($ws->absences(1), static fn (array $x): bool => $x['date'] === '2026-12-25')) === 0);
$add->execute(['dates' => ['2026-12-25'], 'kind' => 'public_holiday'], 1);
$sg2 = (new SuggestWorkHolidays($ws2))->execute(['from' => '2026-12-01', 'to' => '2026-12-31'], 1);
check('once confirmed (marked) it is no longer suggested', !in_array('2026-12-25', array_column($sg2['public'], 'date'), true));
check('another user sees no schedule', $ws->balance(2) === ['configured' => false]);

echo "8) the work card carries the balance\n";
$card = $events->overview(1, ['period' => 'today']);
check('overview has a balance line per scope once a schedule exists', isset($card['balances'][0]['label']) && preg_match('/^[+−]\d+h \d{2}m$/u', $card['balances'][0]['label']) === 1 && $card['balances'][0]['since'] === '2026-11-02', json_encode($card['balances'] ?? null));
check('a user without a schedule gets none', !isset($events->overview(2, ['period' => 'today'])['balances']));

echo "9) report #84: long float digits must not break the stored hours\n";
ini_set('serialize_precision', '17');
$db->exec("DELETE FROM work_schedule WHERE user_id = 3");
$r = (new SetWorkSchedule($ws))->execute(['from' => '2026-09-01', 'hours_per_week' => 22.2, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'place' => 'DTU'], 3);
$raw = (string) $db->query("SELECT hours FROM work_schedule WHERE user_id = 3")->fetchColumn();
check('stored as compact JSON with 4.44, whatever serialize_precision says', $raw === '{"1":4.44,"2":4.44,"3":4.44,"4":4.44,"5":4.44}', $raw);
check('and it reads back with real hours', ($ws->schedules(3)[0]['per_week'] ?? 0) === 22.2 && WorkSchedule::targetMinutes($ws->schedules(3), '2026-09-07', 'DTU') === 266, json_encode($ws->schedules(3)));
$db->exec("INSERT INTO work_schedule (user_id, effective_from, place, hours) VALUES (3, '2026-09-01', 'X', '{\"1\":4.4400000000000003907985')");
check('a damaged row is skipped, not read as "no hours"', count($ws->schedules(3)) === 1);
$w = (new SetWorkSchedule($ws))->execute(['from' => '2026-09-01', 'hours_per_week' => 22.2, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']], 3);
check('a schedule of another scope gets a warning that targets add up', is_string($w['warning'] ?? null) && str_contains($w['warning'], 'ADD UP'), json_encode($w));
$rm = (new SetWorkSchedule($ws))->execute(['from' => '2026-09-01', 'place' => 'DTU', 'remove' => true], 3);
check('remove deletes just that scope', $rm['removed'] === true && count($ws->schedules(3)) === 1 && $ws->schedules(3)[0]['place'] === null);
ini_restore('serialize_precision');

echo "10) report #85: balance PER workplace — a workplace without a schedule (freelance) never inflates another's\n";
$db->exec('DELETE FROM work_events WHERE user_id = 4'); $db->exec('DELETE FROM work_schedule WHERE user_id = 4'); $db->exec('DELETE FROM work_absences WHERE user_id = 4');
$p4 = static function (string $day, string $from, string $to, string $place) use ($db): void {
    $i = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (4, ?, ?, ?)');
    $i->execute(['in', LocalTime::toUtc("$day $from"), $place]); $i->execute(['out', LocalTime::toUtc("$day $to"), $place]);
};
(new SetWorkSchedule($ws))->execute(['from' => '2026-11-02', 'hours_per_week' => 20, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'place' => 'DTU'], 4);   // 4 h a day
$p4('2026-11-02', '08:00', '12:00', 'DTU');      // 4 h = on target
$p4('2026-11-03', '08:00', '11:00', 'DTU');      // 3 h = -1 h
foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $d) { $p4($d, '13:00', '21:00', 'DSB'); }   // 40 h at a place with NO schedule
$sc = $ws->balances(4, null, null, '2026-11-08');
check('only the workplace with a schedule has a balance', count($sc) === 1 && $sc[0]['place'] === 'DTU', json_encode(array_column($sc, 'place')));
$t = $sc[0]['balance']['totals'];
check('DTU: worked 7 h of 20 → −13 h; the 40 h at the unscheduled place are not counted', $t['worked'] === 420 && $t['target'] === 1200 && $t['balance'] === 420 - 1200, json_encode($t));
$tool = (new GetWorkBalance($ws))->execute([], 4);
check('the tool answers per workplace and warns not to add them', count($tool['balances']) === 1 && $tool['balances'][0]['workplace'] === 'DTU' && str_contains($tool['note'], 'Never add'), json_encode($tool['balances'] ?? null));
check('asking for a workplace without a schedule explains instead of guessing', (new GetWorkBalance($ws))->execute(['place' => 'DSB'], 4)['configured'] === false);
(new SetWorkSchedule($ws))->execute(['from' => '2026-11-02', 'hours_per_week' => 10, 'days' => ['mon', 'tue'], 'place' => null], 4);
$both = $ws->balances(4, null, null, '2026-11-08');
check('a combined schedule is its own scope, compared with ALL hours, next to DTU\'s', count($both) === 2 && $both[0]['place'] === null && $both[1]['place'] === 'DTU' && $both[0]['balance']['totals']['worked'] === 420 + 2400 && $both[0]['balance']['totals']['target'] === 600, json_encode(array_map(static fn ($x) => [$x['place'], $x['balance']['totals']], $both)));
$card4 = $events->overview(4, ['period' => 'today']);
check('the card carries one line per scope', count($card4['balances'] ?? []) === 2);

echo "11) clocking out moves the balance at once (today counts, report: refresh did not change the balance)\n";
$db->exec('DELETE FROM work_events WHERE user_id = 5'); $db->exec('DELETE FROM work_schedule WHERE user_id = 5'); $db->exec('DELETE FROM work_absences WHERE user_id = 5');
(new SetWorkSchedule($ws))->execute(['from' => '2026-11-02', 'hours_per_week' => 20, 'days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'place' => 'DTU'], 5);   // 4 h a day
$i5 = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (5, ?, ?, ?)');
$before = $ws->balances(5, null, null, '2026-11-03')[0]['balance'];            // Tuesday, nothing worked yet: Monday's 4 h and today's 4 h are missing
$i5->execute(['in', LocalTime::toUtc('2026-11-03 08:00'), 'DTU']); $i5->execute(['out', LocalTime::toUtc('2026-11-03 11:00'), 'DTU']);
$after = $ws->balances(5, null, null, '2026-11-03')[0]['balance'];
check('before: −8 h (Monday and today unworked); after 3 h today: −5 h — the line moved', $before['totals']['balance'] === -480 && $after['totals']['balance'] === -300, json_encode([$before['totals']['balance'], $after['totals']['balance']]));
check('today\'s progress is carried separately for the card', $after['today']['worked'] === 180 && $after['today']['target'] === 240);

echo "12) the overview card carries the schedule per range and per bar (week / month / year views)\n";
$wk = $events->overview(1, ['date' => '2026-11-02', 'to' => '2026-11-08', 'bucket' => 'day']);
$sc1 = $wk['schedule']['scopes'][0] ?? null;
check('range totals for the week: worked 924, days off 60, target 2220', $sc1 !== null && $sc1['range']['worked'] === 924 && $sc1['range']['credited'] === 60 && $sc1['range']['target'] === 2220 && $sc1['range']['balance'] === 924 + 60 - 2220, json_encode($sc1['range'] ?? null));
check('the running balance since the schedule start rides along', isset($sc1['running']['balance']) && $sc1['running']['since'] === '2026-11-02');
$mon = $wk['bars'][0] ?? [];
check('each bar has its own worked / target / balance (Monday 480 vs 444 = +36)', ($mon['sch'][0]['worked'] ?? null) === 480 && ($mon['sch'][0]['target'] ?? null) === 444 && ($mon['sch'][0]['balance'] ?? null) === 36, json_encode($mon['sch'] ?? null));
$yr = $events->overview(1, ['date' => '2026-01-01', 'to' => '2026-12-31', 'bucket' => 'month']);
check('a calendar-year view has 12 month bars with schedule sums', count($yr['bars'] ?? []) === 12 && isset($yr['bars'][10]['sch'][0]['target']) && $yr['bars'][10]['sch'][0]['worked'] > 0, json_encode($yr['bars'][10] ?? null));
check('a user without a schedule gets no schedule block', !isset($events->overview(9, ['date' => '2026-11-02', 'to' => '2026-11-08', 'bucket' => 'day'])['schedule']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
