<?php

declare(strict_types=1);

/**
 * Report #73: Astrid asked for weights "til Christian" and the assistant could not find his max — he logs "Deadlift"/"Overhead Press", her plan says
 * "Dødløft"/"Pres" — and used her own numbers. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/connected-load-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Connections;
use App\Data\ExerciseAliases;
use App\Data\Workouts;
use App\Support\ExerciseNames;
use App\Tools\GetConnectedWorkouts;
use App\Tools\TrainingLoad;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/connected-load-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) the same lift under another name or language\n";
$chris = ['Bench Press', 'Squat', 'Deadlift', 'Overhead Press', 'Barbell Row'];
check('Dødløft → Deadlift', ExerciseNames::matchIn('Dødløft', $chris) === 'Deadlift');
check('Pres → Overhead Press', ExerciseNames::matchIn('Pres', $chris) === 'Overhead Press');
check('Bænkpres → Bench Press', ExerciseNames::matchIn('bænkpres', $chris) === 'Bench Press');
check('an exact name wins, case-blind', ExerciseNames::matchIn('squat', $chris) === 'Squat');
check('a typo still finds it', ExerciseNames::matchIn('Deadlifft', $chris) === 'Deadlift');
check('something they do not log is not invented', ExerciseNames::matchIn('Front elevated lunge', $chris) === null && ExerciseNames::matchIn('Hip abductors', $chris) === null);
check('Danish and English names of different lifts are not mixed up', ExerciseNames::group('Rumænsk dødløft') === 'romanian' && ExerciseNames::group('Dødløft') === 'deadlift' && ExerciseNames::matchIn('Dødløft', ['Romanian Deadlift']) === null);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$db->exec('CREATE TABLE user_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, requester_id INT, addressee_id INT, status TEXT, requester_scopes TEXT, addressee_scopes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workouts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, exercise TEXT, weight REAL, reps INT, notes TEXT, logged_at TEXT)');
$db->exec('CREATE TABLE exercise_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, alias_norm TEXT, canonical TEXT)');
$db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Christian', 'c@example.test'), (2, 'Astrid', 'a@example.test')");
// Christian (1) shares workouts with Astrid (2); she is the viewer. Astrid logs Danish names, Christian English ones.
$db->exec("INSERT INTO user_connections (requester_id, addressee_id, status, requester_scopes, addressee_scopes) VALUES (1, 2, 'accepted', 'workouts', 'workouts')");
$ins = $db->prepare('INSERT INTO workouts (user_id, exercise, weight, reps, logged_at) VALUES (?, ?, ?, ?, ?)');
foreach ([[1, 'Bench Press', 90, 1, '2026-09-05 10:00:00'], [1, 'Squat', 120, 1, '2026-08-08 10:00:00'], [1, 'Deadlift', 160, 1, '2026-06-01 10:00:00'], [1, 'Deadlift', 140, 3, '2026-06-20 10:00:00'],
          [1, 'Overhead Press', 60, 1, '2026-05-01 10:00:00'], [1, 'Bench Press', 80, 4, '2026-10-30 10:00:00'],
          [2, 'Dødløft', 122.5, 1, '2026-07-03 10:00:00'], [2, 'Pres', 42.5, 2, '2026-07-17 10:00:00']] as $r) {
    $ins->execute($r);
}
$conns = new Connections($db);
$wk    = new Workouts($db);
$tool  = new GetConnectedWorkouts($conns, $wk);
$load  = new TrainingLoad($wk, new ExerciseAliases($db), $conns);

echo "2) get_connected_workouts finds their name for it\n";
$r = $tool->execute(['person' => 'Christian', 'exercise' => 'Dødløft'], 2);
check('Dødløft finds his Deadlift sets and says which name matched', $r['count'] === 2 && ($r['matched_exercise'] ?? '') === 'Deadlift', json_encode([$r['count'], $r['matched_exercise'] ?? null]));
check('…with his records (tested 1RM 160)', ($r['records'][0]['tested_1rm']['weight'] ?? 0) === 160.0);
$r = $tool->execute(['person' => 'Christian', 'exercise' => 'Front elevated lunge'], 2);
check('an exercise he does not log: zero, with what he DOES log and a warning not to use her numbers', $r['count'] === 0 && in_array('Deadlift', $r['known_exercises'] ?? [], true) && str_contains($r['note'] ?? '', 'do not use'), json_encode($r));

echo "3) training_load for ANOTHER person, from THEIR max\n";
$r = $load->execute(['for_person' => 'Christian', 'exercise' => 'Dødløft', 'percent' => 85], 2);
check('85% of HIS deadlift (tested 160 → 136, rounded to 2.5 = 135)', ($r['for_person'] ?? '') === 'Christian' && ($r['results']['tested']['max'] ?? 0) === 160.0 && ($r['results']['tested']['load'] ?? 0) === 135.0, json_encode($r));
check('it is his max, not hers (no your_max, her 122.5 is nowhere)', !isset($r['your_max']) && !str_contains(json_encode($r), '122.5'));
$r = $load->execute(['for_person' => 'Christian', 'exercise' => 'Pres', 'reps' => 2], 2);
check('Pres → his Overhead Press, loads for 2 reps from his estimate/tested max', ($r['exercise'] ?? '') === 'Overhead Press' && isset($r['results']['tested']), json_encode($r));
$r = $load->execute(['for_person' => 'Christian', 'exercise' => 'Hip abductors', 'percent' => 85], 2);
check('no logged max for him: an error that says so and never falls back to hers', isset($r['error']) && isset($r['known_exercises']) && str_contains($r['note'] ?? '', 'Never fall back'), json_encode($r));
$r = $load->execute(['for_person' => 'Christian', 'exercise' => 'Dødløft', 'percent' => 85, 'my_max' => 150], 2);
check('a max stated for him is used', ($r['results']['stated']['max'] ?? 0) === 150.0, json_encode($r));
check('mixing both modes is refused', isset($load->execute(['for_person' => 'Christian', 'person' => 'Christian', 'person_weight' => 100, 'exercise' => 'Squat'], 2)['error']));
check('without the connection nothing is read', isset($load->execute(['for_person' => 'Christian', 'exercise' => 'Squat', 'percent' => 80], 3)['error']));
$r = $load->execute(['exercise' => 'Dødløft', 'percent' => 85], 2);
check('her own loads still come from her own max', ($r['your_max']['tested']['kg'] ?? 0) === 122.5 && !isset($r['for_person']), json_encode($r));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
