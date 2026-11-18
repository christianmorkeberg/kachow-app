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

echo "4) the balance: worked + credited − target, opening balance 0, through yesterday\n";
$punch('2026-11-02', '08:00', '16:00');            // Mon 8h
$punch('2026-11-03', '07:00', '14:24');            // Tue 7h24
// Wed–Fri holiday (3 × 444 credited)
$b = $ws->balance(1, null, null, '2026-11-09');   // "today" is the next Monday
// worked 480+444 = 924; credited 3*444 = 1332; target 5*444 = 2220 → +36
check('balance = +36 min', $b['totals']['balance'] === 36 && $b['totals']['worked'] === 924 && $b['totals']['credited'] === 1332 && $b['totals']['target'] === 2220, json_encode($b['totals']));
check('counted from the schedule start through yesterday', $b['from'] === '2026-11-02' && $b['through'] === '2026-11-08');
$t = (new GetWorkBalance($ws))->execute([], 1);
check('the tool answers with a signed label and the schedule', $t['configured'] === true && isset($t['balance']) && $t['schedule'][0]['per_week'] === 37.0);
check('signed labels', GetWorkBalance::signed(36) === '+0h 36m' && GetWorkBalance::signed(-492) === '−8h 12m');

echo "5) a part-day absence credits its hours; removing days off changes the balance\n";
$add->execute(['dates' => ['2026-11-03'], 'kind' => 'sick', 'hours' => 1], 1);
$b2 = $ws->balance(1, null, null, '2026-11-09');
check('Tuesday: worked 444 + 60 credited', $b2['totals']['credited'] === 1332 + 60 && $b2['totals']['balance'] === 96, json_encode($b2['totals']));
$del = (new DeleteWorkAbsence($ws))->execute(['from' => '2026-11-04', 'to' => '2026-11-06'], 1);
check('delete removes the days and says which', $del['days_removed'] === 3 && count($ws->absences(1)) === 1);
$b3 = $ws->balance(1, null, null, '2026-11-09');
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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
