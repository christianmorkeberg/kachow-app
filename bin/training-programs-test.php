<?php

declare(strict_types=1);

/** Training programmes: the data layer (calendar / relative weeks, repeat, replace, remove) against in-memory SQLite. No network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\TrainingPrograms;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded\n");
    exit(2);
}
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE training_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, status TEXT DEFAULT 'active', week_mode TEXT DEFAULT 'calendar', start_date TEXT, repeat_weeks INT, source_note TEXT, created_at TEXT, updated_at TEXT)");
$db->exec('CREATE TABLE training_program_days (id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INT, week_no INT, year INT DEFAULT 0, weekday INT, title TEXT, note TEXT, UNIQUE (program_id, year, week_no, weekday))');
$db->exec('CREATE TABLE training_program_items (id INTEGER PRIMARY KEY AUTOINCREMENT, day_id INT, position INT, exercise TEXT, sets INT, reps INT, weight REAL, note TEXT)');
$T = new TrainingPrograms($db);

$wed = [['exercise' => 'Deadlift', 'sets' => 1, 'reps' => 4, 'weight' => 117], ['exercise' => 'Shoulder press'], ['exercise' => 'Lat pulldown', 'note' => 'alt: pull-ups'], ['exercise' => '  '], ['exercise' => 'Calf raises', 'sets' => 3, 'reps' => '12', 'weight' => 'heavy']];
$id = $T->create(1, 'Autumn block', 'calendar', null, null, "uge 43: onsdag: ...");
check('a programme is created active, with its note kept', $T->get(1, $id)['status'] === 'active' && $T->get(1, $id)['has_source'] && $T->get(1, $id)['week_mode'] === 'calendar');
$r = $T->upsertDay(1, $id, 43, 2026, 3, 'Pull + legs', $wed);
check('a day is stored; blank exercises are dropped, junk numbers become null', $r === ['items' => 4, 'replaced' => false]);
$days = $T->days(1, $id);
check('…with order, targets and notes', count($days) === 1 && $days[0]['items'][0] === ['exercise' => 'Deadlift', 'sets' => 1, 'reps' => 4, 'weight' => 117.0, 'note' => null] && $days[0]['items'][2]['note'] === 'alt: pull-ups' && $days[0]['items'][3]['weight'] === null && $days[0]['items'][3]['reps'] === 12);
check('setting the same day again replaces its exercises', $T->upsertDay(1, $id, 43, 2026, 3, 'Pull', [['exercise' => 'Row']]) === ['items' => 1, 'replaced' => true] && count($T->days(1, $id)[0]['items']) === 1);
$T->upsertDay(1, $id, 43, 2026, 3, 'Pull + legs', $wed);
$T->upsertDay(1, $id, 44, 2026, 1, 'Push', [['exercise' => 'Bench press', 'sets' => 5, 'reps' => 5, 'weight' => 80]]);
check('summary: days and the weeks it covers', ($T->get(1, $id)['days'] ?? 0) === 2 && $T->get(1, $id)['first_week'] === 43 && $T->get(1, $id)['last_week'] === 44);
check('nonsense days are refused (week 0, weekday 9, someone else\'s programme)', $T->upsertDay(1, $id, 0, 0, 3, null, []) === null && $T->upsertDay(1, $id, 43, 0, 9, null, []) === null && $T->upsertDay(2, $id, 43, 2026, 3, null, $wed) === null);

echo "which day is it?\n";
$d = $T->dayFor(1, '2026-10-21');   // Wednesday of ISO week 43, 2026
check('a calendar programme: Wednesday 21 Oct 2026 is week 43, weekday 3', $d !== null && $d['week'] === 43 && $d['weekday'] === 3 && $d['label'] === 'Autumn block · uge 43' && count($d['items']) === 4);
check('another weekday or week has nothing', $T->dayFor(1, '2026-10-22') === null && $T->dayFor(1, '2026-10-28') === null);
check('Monday of week 44 is the Push day', $T->dayFor(1, '2026-10-26')['title'] === 'Push');
check('a day pinned to 2026 does not match week 43 of another year', $T->dayFor(1, '2027-10-27') === null);
$T->upsertDay(1, $id, 43, 0, 5, 'Any-year Friday', [['exercise' => 'Squat']]);
check('a day with no year matches every year', $T->dayFor(1, '2026-10-23')['title'] === 'Any-year Friday' && $T->dayFor(1, '2027-10-29')['title'] === 'Any-year Friday');
check('another user sees nothing', $T->dayFor(2, '2026-10-21') === null);

$T->update(1, $id, ['status' => 'archived']);
check('an archived programme prescribes nothing', $T->dayFor(1, '2026-10-21') === null);
$T->update(1, $id, ['status' => 'active']);

$rel = $T->create(1, 'Strength 4-week', 'relative', '2026-10-05', 4);
$T->upsertDay(1, $rel, 1, 0, 1, 'A', [['exercise' => 'Squat']]);
$T->upsertDay(1, $rel, 4, 0, 1, 'D', [['exercise' => 'Deload squat']]);
check('relative weeks count from the start date: week 1 is the week of 5 Oct', $T->dayFor(1, '2026-10-05')['title'] === 'A' && $T->dayFor(1, '2026-10-05')['label'] === 'Strength 4-week · week 1');
check('…week 4 is 26 Oct', $T->dayFor(1, '2026-10-26')['title'] === 'D' || $T->dayFor(1, '2026-10-26')['program'] === 'Autumn block');
check('…and it repeats every 4 weeks: 2 Nov is week 1 again', $T->dayFor(1, '2026-11-02')['title'] === 'A');
check('before the start date there is nothing from it', $T->dayFor(1, '2026-09-28') === null);
$T2 = $T->weekFor(['week_mode' => 'relative', 'start_date' => null, 'repeat_weeks' => null], new DateTimeImmutable('2026-10-21'));
check('a relative programme without a start date matches nothing', $T2 === null);

echo "find, remove, delete\n";
check('find by name (any case / part) and id', $T->find(1, 'autumn BLOCK')['id'] === $id && $T->find(1, 'strength')['id'] === $rel && $T->find(1, (string) $id)['id'] === $id && $T->find(1, 'nope') === null);
check('remove one weekday of a week', $T->removeDays(1, $id, 43, 5) === 1 && $T->dayFor(1, '2026-10-23') === null);
check('remove a whole week', $T->removeDays(1, $id, 44, null) === 1 && count($T->days(1, $id)) === 1);
check('someone else cannot remove', $T->removeDays(2, $id, 43, null) === 0);
check('delete takes the days and items with it', $T->delete(1, $id) && $T->get(1, $id) === null && (int) $db->query('SELECT COUNT(*) FROM training_program_days WHERE program_id = ' . $id)->fetchColumn() === 0 && (int) $db->query('SELECT COUNT(*) FROM training_program_items')->fetchColumn() === 2 && !$T->delete(2, $rel));

echo "a programme day becomes the day's plan\n";
$db->exec('CREATE TABLE workout_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, plan_date TEXT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workout_plan_items (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, exercise TEXT, target_sets INT, target_reps INT, target_weight REAL, note TEXT, position INT DEFAULT 0, done INT DEFAULT 0, logged INT DEFAULT 0, done_at TEXT)');
$plans = new \App\Data\WorkoutPlans($db, null);
$planner = new \App\Support\ProgramPlanner($T, $plans);
$P = $T->create(1, 'Block', 'calendar');
$T->upsertDay(1, $P, 43, 2026, 3, 'Pull + legs', [['exercise' => 'Deadlift', 'sets' => 1, 'reps' => 4, 'weight' => 117], ['exercise' => 'Lat pulldown', 'note' => 'alt: pull-ups']]);
$T->upsertDay(1, $P, 43, 2026, 1, 'Push', [['exercise' => 'Bench press']]);
$T->upsertDay(1, $P, 44, 2026, 2, 'Legs', [['exercise' => 'Squat', 'sets' => 5, 'reps' => 5]]);
$r = $planner->ensureDay(1, '2026-10-21', '2026-10-20');
$planId = $plans->planIdForDate(1, '2026-10-21');
$items = $plans->itemsForPlan($planId);
check('asking for tomorrow puts the programme day into the plan, with its targets and notes', $r !== null && $r['items'] === 2 && $r['label'] === 'Block · uge 43' && count($items) === 2
    && (int) $items[0]['target_reps'] === 4 && (float) $items[0]['target_weight'] === 117.0 && $items[1]['note'] === 'alt: pull-ups' && $plans->planById(1, $planId)['title'] === 'Pull + legs');
check('asking again changes nothing (no double rows)', $planner->ensureDay(1, '2026-10-21', '2026-10-20') === null && count($plans->itemsForPlan($planId)) === 2);
$ticked = $plans->itemsForPlan($planId)[0]['id'];
$db->exec('UPDATE workout_plan_items SET done = 1 WHERE id = ' . (int) $ticked);
check('a day she has started (ticked) is not touched', $planner->ensureDay(1, '2026-10-21', '2026-10-21') === null && count($plans->itemsForPlan($planId)) === 2);
check('a past day is never invented', $planner->ensureDay(1, '2026-10-19', '2026-10-20') === null && $plans->planIdForDate(1, '2026-10-19') === null);
$mine = $plans->ensurePlanForDate(1, '2026-10-27');
$plans->addOrMergeItem($mine, 'Her own session');
check('a plan she made herself is never replaced', $planner->ensureDay(1, '2026-10-27', '2026-10-20') === null && count($plans->itemsForPlan($mine)) === 1);
$w = $planner->ensureWeek(1, '2026-10-20', '2026-10-20');
check('a week fills its prescribed days that have no plan yet (Monday the 19th is past, Wednesday is done already)', array_column($w, 'date') === []);
$planner->ensureDay(1, '2026-10-26', '2026-10-20');
check('week 44 Tuesday: no programme day on Monday 26th for this one, but "Legs" on Tuesday', $planner->ensureDay(1, '2026-10-27', '2026-10-20') === null);
check('a date with no programme day gets nothing', $planner->ensureDay(1, '2026-12-24', '2026-10-20') === null && $plans->planIdForDate(1, '2026-12-24') === null);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
