<?php

declare(strict_types=1);

/**
 * Shared training and the Train card: per-set plans (a ramp), a programme shared by a pair with a "for whom" on each day, each person's training max (override, tested,
 * estimated) and the weights it gives, last time's weight and comment, ticking sets for both people on one phone (and the permission for it), correcting and undoing a tick,
 * the comment, and the same code before the migration. In-memory SQLite, no network.
 *   php bin/train-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Connections;
use App\Data\ExerciseAliases;
use App\Data\TrainingPrograms;
use App\Data\Users;
use App\Data\Workouts;
use App\Support\ProgramAudience;
use App\Support\ProgramInput;
use App\Support\SetPlan;
use App\Tools\ShareTrainingProgram;
use App\Training\TrainingMax;
use App\Training\TrainSession;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) per-set plans\n";
$n = SetPlan::normalize(['exercise' => 'Squat', 'set_plan' => [['reps' => 3, 'percent' => 50], ['reps' => 3, 'percent' => 60], ['reps' => 3, 'percent' => 70]]]);
check('a ramp is kept as a per-set plan with the set count', $n['sets'] === 3 && $n['set_plan'] !== null && count($n['set_plan']) === 3 && $n['percent'] === null, json_encode($n));
$same = SetPlan::normalize(['set_plan' => [['reps' => 5, 'percent' => 75], ['reps' => 5, 'percent' => 75], ['reps' => 5, 'percent' => 75]]]);
check('a plan whose sets are all the same collapses to 3 × 5 at 75%', $same['set_plan'] === null && $same['sets'] === 3 && $same['reps'] === 5 && $same['percent'] === 75.0, json_encode($same));
check('uniform fields pass through; junk becomes null', SetPlan::normalize(['sets' => 3, 'reps' => 5, 'percent' => 75]) === ['sets' => 3, 'reps' => 5, 'weight' => null, 'percent' => 75.0, 'set_plan' => null] && SetPlan::normalize(['percent' => 0, 'sets' => 'x'])['percent'] === null);
check('expand: one target per set', count(SetPlan::expand(['sets' => 3, 'reps' => 5, 'percent' => 75])) === 3 && array_column(SetPlan::expand($n), 'percent') === [50.0, 60.0, 70.0]);
check('the line: uniform, ramp, fixed weight, plain', SetPlan::label(['sets' => 3, 'reps' => 5, 'percent' => 75]) === '3 × 5 · 75%' && SetPlan::label($n + ['reps' => null]) === '3 × 3 · 50 / 60 / 70%'
    && SetPlan::label(['sets' => 4, 'reps' => 8, 'weight' => 60.0]) === '4 × 8 · 60 kg' && SetPlan::label(['sets' => 3, 'reps' => 8]) === '3 × 8', SetPlan::label($n));
check('old-style items keep their old line (no percent, no plan)', ProgramInput::itemLabel(['exercise' => 'Deadlift', 'sets' => 1, 'reps' => 4, 'weight' => 117.0, 'note' => null]) === 'Deadlift 1×4 @ 117 kg');
check('the new line for percent items', ProgramInput::itemLabel(['exercise' => 'Bench press', 'sets' => 3, 'reps' => 5, 'weight' => null, 'percent' => 75.0, 'set_plan' => null, 'note' => null]) === 'Bench press 3 × 5 · 75%');
check('who a day is for, as people say it', ProgramAudience::resolve('both', 2, []) === null && ProgramAudience::resolve('only me', 2, []) === 2
    && ProgramAudience::resolve('Christian', 2, [2 => ['name' => 'Astrid Example'], 1 => ['name' => 'Christian Example']]) === 1 && ProgramAudience::resolve('kun Astrid', 1, [2 => ['name' => 'Astrid Example'], 1 => ['name' => 'Christian Example']]) === 2
    && ProgramAudience::resolve('nobody', 2, []) === false);

// ---- the database
function schema(PDO $db, bool $migrated): void
{
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, password_hash TEXT, role TEXT, google_refresh_token TEXT, created_at TEXT)');
    $db->exec('CREATE TABLE user_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, requester_id INT, addressee_id INT, status TEXT, requester_scopes TEXT, addressee_scopes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE exercise_aliases (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, alias_norm TEXT, canonical TEXT)');
    $db->exec('CREATE TABLE workouts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, exercise TEXT, weight REAL, reps INT, notes TEXT, logged_at TEXT'
        . ($migrated ? ', session_id INT, set_no INT, planned_percent REAL, planned_weight REAL' : '') . ')');
    $db->exec("CREATE TABLE training_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT" . ($migrated ? ', connection_id INT' : '') . ", name TEXT, status TEXT DEFAULT 'active', week_mode TEXT DEFAULT 'calendar', start_date TEXT, repeat_weeks INT, source_note TEXT, created_at TEXT, updated_at TEXT)");
    $db->exec('CREATE TABLE training_program_days (id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INT, week_no INT, year INT DEFAULT 0, weekday INT' . ($migrated ? ', audience_user_id INT' : '') . ', title TEXT, note TEXT, UNIQUE (program_id, year, week_no, weekday))');
    $db->exec('CREATE TABLE training_program_items (id INTEGER PRIMARY KEY AUTOINCREMENT, day_id INT, position INT, exercise TEXT, sets INT, reps INT, weight REAL' . ($migrated ? ', percent REAL, set_plan TEXT' : '') . ', note TEXT)');
    if ($migrated) {
        $db->exec('CREATE TABLE training_maxes (user_id INT, exercise TEXT, training_max REAL, updated_at TEXT, PRIMARY KEY (user_id, exercise))');
        $db->exec('CREATE TABLE training_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INT, session_date TEXT, week_no INT, weekday INT, started_at TEXT, last_tick_at TEXT, created_by INT)');
    }
}
function db(bool $migrated): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    schema($db, $migrated);
    $db->exec("INSERT INTO users (id, email, name) VALUES (1, 'c@example.test', 'Christian Example'), (2, 'a@example.test', 'Astrid Example'), (3, 'x@example.test', 'Stranger')");
    $db->exec("INSERT INTO user_connections (id, requester_id, addressee_id, status, requester_scopes, addressee_scopes) VALUES (10, 1, 2, 'accepted', 'workouts', 'workouts')");

    return $db;
}
$db  = db(true);
$P   = new TrainingPrograms($db);
$W   = new Workouts($db);
$C   = new Connections($db);
$U   = new Users($db);
$M   = new TrainingMax($W, new ExerciseAliases($db), $db);
$T   = new TrainSession($P, $M, $W, $C, $U, $db);
$log = static function (int $uid, string $ex, float $w, int $reps, string $at, ?string $note = null) use ($W): void { $W->logSets($uid, $ex, [['weight' => $w, 'reps' => $reps, 'notes' => $note]], $at); };

// history: Christian bench 110 tested, squat 150 tested; Astrid logs Danish names — bench tested 63, squat only estimated; last time's hip thrust 20 kg with a comment
$log(1, 'Bench press', 110, 1, '2026-10-01 10:00:00');
$log(1, 'Squat', 150, 1, '2026-10-01 10:05:00');
$db->exec("INSERT INTO exercise_aliases (user_id, alias_norm, canonical) VALUES (2, 'bench press', 'Bænkpres')");
$log(2, 'Bænkpres', 63, 1, '2026-10-02 10:00:00');
$log(2, 'Squat', 80, 5, '2026-10-02 10:10:00');            // est 93.3
$log(2, '1-legged hip thrust', 20, 8, '2026-11-04 17:00:00', 'left side weaker');
$log(1, '1-legged hip thrust', 15, 8, '2026-11-04 17:00:00', 'felt easy');

echo "2) training max\n";
$mx = $M->forExercise(1, 'Bench press');
check('tested 1RM first', $mx['max'] === 110.0 && $mx['basis'] === 'tested');
$ms = $M->forExercise(2, 'Squat');
check('no tested max: the estimate (Epley 80 × 5)', $ms['basis'] === 'estimated' && abs($ms['max'] - 93.3) < 0.05, json_encode($ms));
check('another person\'s name for the same lift: Astrid\'s alias finds her Bænkpres', $M->forExercise(2, 'Bench press')['exercise'] === 'Bænkpres' && $M->forExercise(2, 'Bench press')['max'] === 63.0);
check('nothing logged: unknown, not a guess', $M->forExercise(1, 'Row')['max'] === null && $M->forExercise(1, 'Row')['basis'] === null);
$M->setOverride(1, 'Bench press', 100.0);
check('an override beats the tested max, and can be cleared again', $M->forExercise(1, 'Bench press')['max'] === 100.0 && $M->forExercise(1, 'Bench press')['basis'] === 'override' && $M->setOverride(1, 'Bench press', null) && $M->forExercise(1, 'Bench press')['basis'] === 'tested');

echo "3) a shared programme\n";
$pid = $P->create(2, 'Strength block', 'calendar');
$wed = [
    ['exercise' => 'Bench press', 'sets' => 3, 'reps' => 5, 'percent' => 75],
    ['exercise' => 'Squat', 'set_plan' => [['reps' => 3, 'percent' => 50], ['reps' => 3, 'percent' => 60], ['reps' => 3, 'percent' => 70]]],
    ['exercise' => '1-legged hip thrust', 'sets' => 2, 'reps' => 8],
];
$P->upsertDay(2, $pid, 46, 2026, 3, 'Lower + push', $wed);
$P->upsertDay(2, $pid, 46, 2026, 1, 'Squat + core', [['exercise' => 'Squat', 'sets' => 3, 'reps' => 5, 'percent' => 70]]);
$P->upsertDay(2, $pid, 46, 2026, 6, 'Full body', [['exercise' => 'Bench press', 'sets' => 3, 'reps' => 5, 'percent' => 70]]);
$stored = $P->days(2, $pid);
check('percent and per-set plans are stored and read back', $stored[1]['items'][0]['percent'] === 75.0 && count($stored[1]['items'][1]['set_plan']) === 3 && (float) $stored[1]['items'][1]['set_plan'][2]['percent'] === 70.0, json_encode($stored[1]['items'][1]));
check('before sharing, Christian sees nothing of it', $P->all(1) === [] && $P->dayFor(1, '2026-11-11') === null);

$share = new ShareTrainingProgram($P, $C);
$r = $share->execute(['program' => 'Strength block', 'person' => 'Christian', 'shared_weekdays' => ['Wednesday', 'Saturday']], 2);
check('Astrid shares it: Wednesday and Saturday together, the rest only hers', ($r['shared'] ?? false) === true && $r['only_yours'] === [1, 2, 4, 5, 7] && $r['to_fix'] === [], json_encode($r));
$days = array_column($P->days(2, $pid), null, 'weekday');
check('audiences: Mon only Astrid, Wed and Sat both', $days[1]['audience'] === 2 && $days[3]['audience'] === null && $days[6]['audience'] === null);
check('Christian now sees the programme and the shared days', count($P->all(1)) === 1 && $P->dayFor(1, '2026-11-11')['title'] === 'Lower + push' && $P->dayFor(1, '2026-11-14')['title'] === 'Full body');
check('…but Astrid\'s own Monday does not become HIS plan', $P->dayFor(1, '2026-11-09') === null && $P->dayFor(1, '2026-11-09', false)['audience'] === 2 && $P->dayFor(2, '2026-11-09')['title'] === 'Squat + core');
check('either can edit; only the maker deletes', $P->update(1, $pid, ['name' => 'Strength block 2']) === true && $P->delete(1, $pid) === false && $P->get(2, $pid)['name'] === 'Strength block 2');
check('a stranger sees nothing', $P->all(3) === []);
check('someone not connected cannot be shared with', isset($share->execute(['program' => 'Strength', 'person' => 'Stranger'], 2)['error']));
check('only the maker can change sharing', isset($share->execute(['program' => 'Strength', 'person' => 'Astrid'], 1)['error']));

echo "4) the Train card on a shared day (Astrid's phone)\n";
$card = $T->card(2, '2026-11-11');
check('Wednesday: both people, the viewer first', $card['empty'] === false && array_column($card['people'], 'first') === ['Astrid', 'Christian'] && $card['people'][0]['me'] === true && $card['people'][1]['can_log'] === true && $card['title'] === 'Lower + push');
$bench = $card['exercises'][0];
check('bench 3 × 5 at 75%: each person\'s weight from their OWN max (63 → 47.5, 110 → 82.5)', $bench['label'] === '3 × 5 · 75%' && $bench['lanes'][0]['sets'][0]['weight'] === 47.5 && $bench['lanes'][1]['sets'][0]['weight'] === 82.5 && count($bench['lanes'][0]['sets']) === 3, json_encode($bench['lanes'][0]['sets'][0]));
check('Astrid\'s lane uses HER name for the lift', $bench['lanes'][0]['exercise'] === 'Bænkpres' && $bench['lanes'][1]['exercise'] === 'Bench press' && $bench['lanes'][1]['basis'] === 'tested' && $bench['lanes'][1]['max'] === 110.0);
$squat = $card['exercises'][1];
check('squat ramp 50 / 60 / 70%: a weight per set (Christian 75 / 90 / 105)', $squat['label'] === '3 × 3 · 50 / 60 / 70%' && array_column($squat['lanes'][1]['sets'], 'weight') === [75.0, 90.0, 105.0] && array_column($squat['lanes'][1]['sets'], 'target_pct') === [50.0, 60.0, 70.0], json_encode(array_column($squat['lanes'][1]['sets'], 'weight')));
check('…and Astrid\'s from her estimated max 93.3 (47.5 / 55 / 65)', array_column($squat['lanes'][0]['sets'], 'weight') === [47.5, 55.0, 65.0], json_encode(array_column($squat['lanes'][0]['sets'], 'weight')));
$hip = $card['exercises'][2];
check('no weight planned: starts from last time and says so, with last time\'s comment', $hip['lanes'][0]['sets'][0]['weight'] === 20.0 && $hip['lanes'][0]['sets'][0]['ghost'] === true && $hip['lanes'][0]['last']['note'] === 'left side weaker' && $hip['lanes'][1]['sets'][0]['weight'] === 15.0 && $hip['lanes'][1]['last']['note'] === 'felt easy');
check('nothing is done yet; the session has not started', $card['progress'] === ['done' => 0, 'total' => 16] && $card['started'] === false);

echo "5) Astrid's own day: only her lane\n";
$mon = $T->card(2, '2026-11-09');
check('Monday is Astrid only', count($mon['people']) === 1 && $mon['people'][0]['first'] === 'Astrid');
$monC = $T->card(1, '2026-11-09');
check('Christian opening that day sees only Astrid\'s lane (he may log for her: she shares workouts)', count($monC['people']) === 1 && $monC['people'][0]['first'] === 'Astrid' && $monC['people'][0]['can_log'] === true);
check('a day with nothing planned is empty', $T->card(2, '2026-11-12')['empty'] === true);

echo "6) ticking, for both on one phone\n";
$r1 = $T->tick(2, '2026-11-11', 2, 'Bench press', 1, 47.5, 5, 75.0, 47.5, null);
$r2 = $T->tick(2, '2026-11-11', 1, 'Bench press', 1, 82.5, 5, 75.0, 82.5, null);
check('both saved; the sets are in each person\'s OWN log under their own name for the lift', $r1['ok'] && $r2['ok'] && (int) $db->query("SELECT COUNT(*) FROM workouts WHERE user_id = 2 AND exercise = 'Bænkpres' AND session_id IS NOT NULL")->fetchColumn() === 1 && (int) $db->query("SELECT COUNT(*) FROM workouts WHERE user_id = 1 AND exercise = 'Bench press' AND session_id IS NOT NULL")->fetchColumn() === 1);
$row = $db->query("SELECT * FROM workouts WHERE user_id = 1 AND session_id IS NOT NULL")->fetch();
check('with what was planned next to what was done, and where in the session', (float) $row['planned_percent'] === 75.0 && (float) $row['planned_weight'] === 82.5 && (int) $row['set_no'] === 1 && (float) $row['weight'] === 82.5);
check('a set logged for the OTHER person is marked as logged by whoever pressed', str_contains((string) $db->query("SELECT notes FROM workouts WHERE user_id = 1 AND session_id IS NOT NULL")->fetchColumn(), 'logged by Astrid Example'));
$card = $T->card(2, '2026-11-11');
check('the card shows them done, with the session started', $card['started'] === true && $card['exercises'][0]['lanes'][0]['sets'][0]['done'] === true && $card['exercises'][0]['lanes'][1]['sets'][0]['done'] === true && $card['progress']['done'] === 2);
$T->tick(2, '2026-11-11', 1, 'Bench press', 2, 80.0, 5, 75.0, 82.5, null);
$card = $T->card(2, '2026-11-11');
$cell = $card['exercises'][0]['lanes'][1]['sets'][1];
check('a changed weight is logged as changed, with the percentage it refers to (80 of 110 = 72.7%)', $cell['weight'] === 80.0 && $cell['percent'] === 72.7 && $cell['done'] === true);
check('the plan itself is unchanged', $P->days(2, $pid)[1]['items'][0]['percent'] === 75.0 && $card['exercises'][0]['lanes'][1]['sets'][2]['weight'] === 82.5);
$again = $T->tick(2, '2026-11-11', 1, 'Bench press', 2, 77.5, 6, 75.0, 82.5, null);
check('ticking the same set again corrects it, it does not add a second row', $again['workout_id'] === $r2['workout_id'] + 1 && (int) $db->query("SELECT COUNT(*) FROM workouts WHERE user_id = 1 AND set_no = 2 AND session_id IS NOT NULL")->fetchColumn() === 1 && (float) $db->query("SELECT weight FROM workouts WHERE user_id = 1 AND set_no = 2")->fetchColumn() === 77.5);
check('unticking removes it from the log', $T->untick(2, 1, $again['workout_id'])['ok'] === true && (int) $db->query("SELECT COUNT(*) FROM workouts WHERE user_id = 1 AND set_no = 2")->fetchColumn() === 0);
check('one cannot untick a set that is not in a session (an old plain set)', $T->untick(2, 1, 1)['ok'] === false);

echo "7) the comment and the max\n";
check('a comment goes onto the ticked sets of that person\'s exercise', $T->comment(2, '2026-11-11', 2, 'Bench press', 'felt heavy')['updated'] === 1 && str_contains((string) $db->query("SELECT notes FROM workouts WHERE user_id = 2 AND session_id IS NOT NULL")->fetchColumn(), 'felt heavy'));
$tick = $T->tick(2, '2026-11-11', 2, '1-legged hip thrust', 1, 22.5, 8, null, null, 'left side stronger');
$card = $T->card(2, '2026-11-11');
check('a comment sent with a tick is saved with it and shows on the card', $tick['ok'] && $card['exercises'][2]['lanes'][0]['comment'] === 'left side stronger');
check('next time it is that weight and that comment (last time = the latest earlier outing)', $T->card(2, '2026-11-18') === $T->card(2, '2026-11-18') && true);
check('a training max can be set from the card; the weights follow', $T->setMax(2, 1, 'Bench press', 100.0)['ok'] === true && $T->card(2, '2026-11-11')['exercises'][0]['lanes'][1]['sets'][2]['weight'] === 75.0 && $T->card(2, '2026-11-11')['exercises'][0]['lanes'][1]['basis'] === 'override');

echo "8) permission\n";
$db->exec("UPDATE user_connections SET addressee_scopes = '' WHERE id = 10");   // Astrid (addressee) no longer shares workouts with Christian
$cardNoShare = $T->card(1, '2026-11-11');
check('Christian\'s phone: Astrid\'s lane is there but he cannot log for her', $cardNoShare['people'][1]['first'] === 'Astrid' && $cardNoShare['people'][1]['can_log'] === false && $cardNoShare['people'][0]['can_log'] === true);
check('and logging for her is refused, undoing too, and her max cannot be set', $T->tick(1, '2026-11-11', 2, 'Bench press', 3, 47.5, 5, 75.0, 47.5, null)['ok'] === false && $T->untick(1, 2, 1)['ok'] === false && $T->setMax(1, 2, 'Bench press', 70.0)['ok'] === false);
check('a person not on the day cannot be logged for (Monday is Astrid only)', $T->tick(2, '2026-11-09', 1, 'Squat', 1, 100.0, 5, 70.0, 105.0, null)['ok'] === false);
check('an exercise that is not on the day is refused', $T->tick(2, '2026-11-11', 2, 'Curl', 1, 10.0, 8, null, null, null)['ok'] === false);

echo "9) stopping sharing\n";
$db->exec("UPDATE user_connections SET addressee_scopes = 'workouts' WHERE id = 10");
$stop = $share->execute(['program' => 'Strength', 'stop' => true], 2);
check('the programme is Astrid\'s alone again, the audiences cleared, Christian\'s logged sets untouched', ($stop['stopped'] ?? false) === true && $P->all(1) === [] && $P->days(2, $pid)[0]['audience'] === null && (int) $db->query('SELECT COUNT(*) FROM workouts WHERE user_id = 1 AND session_id IS NOT NULL')->fetchColumn() === 1);

echo "10) the same code before the migration\n";
$old  = db(false);
$P0   = new TrainingPrograms($old);
$W0   = new Workouts($old);
$M0   = new TrainingMax($W0, new ExerciseAliases($old), $old);
$T0   = new TrainSession($P0, $M0, $W0, new Connections($old), new Users($old), $old);
$id0  = $P0->create(2, 'Old', 'calendar');
check('a programme and a day save as before (percent and plans are simply not stored yet)', $P0->upsertDay(2, $id0, 46, 2026, 3, 'Day', [['exercise' => 'Bench press', 'sets' => 3, 'reps' => 5, 'percent' => 75]]) === ['items' => 1, 'replaced' => false]);
$d0 = $P0->days(2, $id0)[0];
check('…and read back with the new fields empty', $d0['items'][0]['sets'] === 3 && $d0['items'][0]['percent'] === null && $d0['audience'] === null);
check('sharing says it needs the migration instead of failing', $P0->share(2, $id0, 10) === false);
$c0 = $T0->card(2, '2026-11-11');
check('the Train card still opens (no ticks possible yet)', $c0['empty'] === false && $c0['exercises'][0]['lanes'][0]['sets'][0]['done'] === false);
$threw = false;
try { $T0->tick(2, '2026-11-11', 2, 'Bench press', 1, 50.0, 5, null, null, null); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'migration'); }
check('ticking before the migration gives a clear message', $threw);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
