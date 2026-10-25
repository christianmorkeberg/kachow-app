<?php

declare(strict_types=1);

/** check_off_exercise: a past programme day becomes the plan when ticked off; all=true ticks the whole day. In-memory SQLite, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\TrainingPrograms;
use App\Data\WorkoutPlans;
use App\Support\ProgramPlanner;
use App\Tools\CheckOffExercise;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE training_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, status TEXT DEFAULT 'active', week_mode TEXT DEFAULT 'calendar', start_date TEXT, repeat_weeks INT, source_note TEXT, created_at TEXT, updated_at TEXT)");
$db->exec('CREATE TABLE training_program_days (id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INT, week_no INT, year INT DEFAULT 0, weekday INT, title TEXT, note TEXT, UNIQUE (program_id, year, week_no, weekday))');
$db->exec('CREATE TABLE training_program_items (id INTEGER PRIMARY KEY AUTOINCREMENT, day_id INT, position INT, exercise TEXT, sets INT, reps INT, weight REAL, note TEXT)');
$db->exec('CREATE TABLE workout_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, plan_date TEXT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workout_plan_items (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, exercise TEXT, target_sets INT, target_reps INT, target_weight REAL, note TEXT, position INT DEFAULT 0, done INT DEFAULT 0, logged INT DEFAULT 0, done_at TEXT)');
$T = new TrainingPrograms($db);
$plans = new WorkoutPlans($db, null);
$tool = new CheckOffExercise($plans, new ProgramPlanner($T, $plans));

$P = $T->create(1, 'Block', 'calendar');
$T->upsertDay(1, $P, 43, 2026, 6, 'Saturday', [['exercise' => 'Squat', 'sets' => 1, 'reps' => 4, 'weight' => 75], ['exercise' => 'Bench press'], ['exercise' => 'Plank']]);

check('no exercise and no all: asks which one', isset($tool->execute(['date' => '2026-10-24'], 1)['error']));
$r = $tool->execute(['exercise' => 'Squat', 'date' => '2026-10-24'], 1);
check('a PAST programme day becomes the plan when one of its exercises is ticked off', ($r['checked_off'] ?? null) === 'Squat' && count($plans->itemsForPlan((int) $plans->planIdForDate(1, '2026-10-24'))) === 3, json_encode($r));
$r = $tool->execute(['all' => true, 'date' => '2026-10-24'], 1);
check('all=true ticks the rest of the day in one call', ($r['checked_off'] ?? []) === ['Bench press', 'Plank'] && isset($r['_render']), json_encode($r));
check('…and every item is done', (int) $db->query('SELECT COUNT(*) FROM workout_plan_items WHERE done = 0')->fetchColumn() === 0);
$r = $tool->execute(['all' => true, 'date' => '2026-10-23'], 1);
check('a day with neither a plan nor a programme day still says nothing is planned', ($r['error'] ?? '') === 'There is no workout planned for 2026-10-23.');
$r = $tool->execute(['exercise' => 'Squat', 'date' => '2026-10-23'], 1);
check('…also for a single exercise', isset($r['error']) && $plans->planIdForDate(1, '2026-10-23') === null);
check('the schema needs no exercise', !in_array('exercise', $tool->parameters()['required'] ?? [], true));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
