<?php

declare(strict_types=1);

/**
 * Workout plans never get the same exercise twice (report #39): WorkoutPlans::addOrMergeItem, the two
 * planning tools on top of it, and the one-off cleanup migration for copies already stored. In-memory
 * SQLite, no network — the migration file itself is executed:
 *   php -d extension=php_pdo_sqlite bin/workout-plan-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\WorkoutPlans;
use App\Tools\AddPlanExercise;
use App\Tools\CreateWorkoutPlan;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/workout-plan-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE workout_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, plan_date TEXT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workout_plan_items (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, exercise TEXT, target_sets INT, target_reps INT,
    target_weight REAL, note TEXT, position INT DEFAULT 0, done INT DEFAULT 0, logged INT DEFAULT 0, done_at TEXT)');
$plans  = new WorkoutPlans($db, null);
$create = new CreateWorkoutPlan($plans);
$add    = new AddPlanExercise($plans);
$names  = static fn (int $planId): array => array_map(static fn (array $r): string => (string) $r['exercise'], $plans->itemsForPlan($planId));
$count  = static fn (int $planId): int => count($plans->itemsForPlan($planId));

echo "1) the same exercise is not added twice\n";
$p = $plans->ensurePlanForDate(1, '2026-10-17');
$a = $plans->addOrMergeItem($p, 'Squat', 3, 5, 100.0);
check('a new exercise is added', $a['result'] === 'added' && $count($p) === 1);
$b = $plans->addOrMergeItem($p, 'squat ', 3, 5, 100.0);
check('the same again (other case, stray space) is left alone', $b['result'] === 'exists' && $b['id'] === $a['id'] && $count($p) === 1);
$c = $plans->addOrMergeItem($p, 'Squat');
check('a bare repeat of an exercise that has targets adds nothing', $c['result'] === 'exists' && $count($p) === 1);

echo "\n2) a bare exercise gets its targets filled in, not a second row\n";
$plans->addOrMergeItem($p, 'Bench');
$f = $plans->addOrMergeItem($p, 'Bench', null, 5, 45.0, 'Pause bench');
$row = $plans->itemsForPlan($p)[1];
check('filled in on the existing row', $f['result'] === 'filled' && $count($p) === 2 && (int) $row['target_reps'] === 5
    && (float) $row['target_weight'] === 45.0 && $row['note'] === 'Pause bench');
check('filling in again is a no-op', $plans->addOrMergeItem($p, 'Bench', null, 5, 45.0, 'pause bench')['result'] === 'exists' && $count($p) === 2);

echo "\n3) different targets are a genuine second block\n";
$s = $plans->addOrMergeItem($p, 'Squat', 3, 8, 80.0);
check('3×8 @ 80 next to 3×5 @ 100 is kept', $s['result'] === 'added' && $count($p) === 3);
check('a conflict on the weight alone counts too', $plans->addOrMergeItem($p, 'Squat', 3, 5, 102.5)['result'] === 'added' && $count($p) === 4);

echo "\n4) a ticked-off exercise is history\n";
$db->exec("UPDATE workout_plan_items SET done = 1 WHERE exercise = 'Bench'");
check('the same name after it was done is a new item', $plans->addOrMergeItem($p, 'Bench')['result'] === 'added');

echo "\n5) the tools say what happened\n";
$day = '2026-11-02';
$r1 = $create->execute(['date' => $day, 'exercises' => [['exercise' => 'Deadlift'], ['exercise' => 'Row']]], 1);
check('first send adds both', $r1['added'] === ['Deadlift', 'Row'] && !isset($r1['already_planned']));
$r2 = $create->execute(['date' => $day, 'exercises' => [['exercise' => 'Deadlift', 'sets' => 3, 'reps' => 5, 'weight' => 120], ['exercise' => 'Row'], ['exercise' => 'Curl']]], 1);
$id = $plans->planIdForDate(1, $day);
check('sending the program again duplicates nothing', $count($id) === 3 && $r2['added'] === ['Curl'] && $r2['filled_in'] === ['Deadlift'] && $r2['already_planned'] === ['Row'], json_encode($r2['added'] ?? null));
check('and tells the model so', str_contains((string) ($r2['note'] ?? ''), 'never duplicated'));
check('no exercise at all is still an error', isset($create->execute(['date' => $day, 'exercises' => [['exercise' => ' ']]], 1)['error']));
$a1 = $add->execute(['exercise' => 'Row', 'date' => $day], 1);
check('add_plan_exercise on an existing one reports it', ($a1['already_planned'] ?? '') === 'Row' && $count($id) === 3);
$a2 = $add->execute(['exercise' => 'Curl', 'reps' => 12, 'date' => $day], 1);
check('add_plan_exercise fills in targets', ($a2['filled_in'] ?? '') === 'Curl' && $count($id) === 3);
check('add_plan_exercise still adds a new one', ($add->execute(['exercise' => 'Dips', 'date' => $day], 1)['added'] ?? '') === 'Dips' && $count($id) === 4);

echo "\n6) the one-off cleanup migration (the real file) on a plan written twice\n";
$q  = $plans->ensurePlanForDate(2, '2026-10-17');
$ins = $db->prepare('INSERT INTO workout_plan_items (plan_id, exercise, target_sets, target_reps, target_weight, note, done, logged) VALUES (?,?,?,?,?,?,?,?)');
// first write: bare names
foreach (['Squat', 'Bench', 'Weighted plank', 'Machine row', 'One legged hip trust', 'Calf raises'] as $n) { $ins->execute([$q, $n, null, null, null, null, 0, 0]); }
// second write: two with targets, the rest bare again (with a corrected spelling)
$ins->execute([$q, 'Squat', null, 5, 65.0, 'Pause squat', 0, 0]);
$ins->execute([$q, 'Bench', null, 5, 45.0, 'Pause bench', 0, 0]);
foreach (['Weighted plank', 'Machine row', 'One legged hip thrust', 'Calf raises'] as $n) { $ins->execute([$q, $n, null, null, null, null, 0, 0]); }
// untouched neighbours the migration must not change
$other = $plans->ensurePlanForDate(3, '2026-10-17');
$ins->execute([$other, 'Squat', null, null, null, null, 0, 0]);                 // same name, DIFFERENT plan
$done  = $plans->ensurePlanForDate(4, '2026-10-17');
$ins->execute([$done, 'Squat', null, null, null, null, 1, 0]);                  // a ticked bare row next to a richer one
$ins->execute([$done, 'Squat', 3, 5, 100.0, null, 0, 0]);
$twoBlocks = $plans->ensurePlanForDate(5, '2026-10-17');
$ins->execute([$twoBlocks, 'Squat', 3, 5, 100.0, null, 0, 0]);                  // two different blocks stay
$ins->execute([$twoBlocks, 'Squat', 3, 8, 80.0, null, 0, 0]);
check('before: 12 rows in the doubled plan', $count($q) === 12);

$sql = (string) file_get_contents(__DIR__ . '/../migrations/2026-10-17_dedupe_workout_plan_items.sql');
$db->exec($sql);
$expected = ['Bench', 'Calf raises', 'Machine row', 'One legged hip thrust', 'One legged hip trust', 'Squat', 'Weighted plank'];
$got      = $names($q);
sort($got);
check('after: each exercise once (the misspelt twin stays for a human)', $got === $expected, json_encode($got));
$rows = $plans->itemsForPlan($q);
$byName = [];
foreach ($rows as $r) { $byName[$r['exercise']][] = $r; }
check('the rows with targets survived, the bare copies went', count($byName['Squat']) === 1 && (int) $byName['Squat'][0]['target_reps'] === 5
    && count($byName['Bench']) === 1 && $byName['Bench'][0]['note'] === 'Pause bench');
check('identical bare copies collapsed to one', count($byName['Weighted plank']) === 1 && count($byName['Machine row']) === 1 && count($byName['Calf raises']) === 1);
check('the misspelt twin is left alone', isset($byName['One legged hip trust']) && isset($byName['One legged hip thrust']));
check('another user\'s plan is untouched', $count($other) === 1);
check('a ticked-off row is never deleted', $count($done) === 2);
check('two different blocks stay', $count($twoBlocks) === 2);
$db->exec($sql);
check('running it again changes nothing', $count($q) === 7 && $count($done) === 2 && $count($twoBlocks) === 2);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
