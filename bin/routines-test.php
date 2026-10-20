<?php

declare(strict_types=1);

/**
 * Routines: the conditions gate, enter/leave detection from the user's own place shapes, and the routines data layer
 * (drafts, phrase lookup, requested runs, place state, ask-first questions). In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/routines-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Conversations;
use App\Data\Places;
use App\Data\Routines;
use App\Data\ScheduledTasks;
use App\Tools\CreateRoutine;
use App\Tools\RunRoutine;
use App\Tools\UpdateRoutine;
use App\Voice\VoiceTools;
use App\Support\PlaceTransitions;
use App\Support\RoutineGate;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/routines-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}
$tz  = new DateTimeZone('Europe/Copenhagen');
$at  = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s, $tz);

echo "1) the conditions gate\n";
$mon = $at('2026-10-19 08:30');   // a Monday
check('no conditions → allowed', RoutineGate::check([], $mon, null) === null);
check('weekdays: allowed on a listed day, held back on another', RoutineGate::check(['weekdays' => [1, 2, 3, 4, 5]], $mon, null) === null && RoutineGate::check(['weekdays' => [6, 7]], $mon, null) !== null);
check('between: inside / outside', RoutineGate::check(['between' => ['07:00', '22:00']], $mon, null) === null && RoutineGate::check(['between' => ['09:00', '22:00']], $mon, null) !== null);
check('between wraps midnight', RoutineGate::check(['between' => ['22:00', '06:00']], $at('2026-10-19 23:30'), null) === null && RoutineGate::check(['between' => ['22:00', '06:00']], $at('2026-10-19 12:00'), null) !== null);
check('cooldown: held back inside, allowed after', RoutineGate::check(['cooldown_minutes' => 30], $mon, $at('2026-10-19 08:10')) !== null && RoutineGate::check(['cooldown_minutes' => 30], $mon, $at('2026-10-19 07:50')) === null);
check('away: blocks only when known to be home', RoutineGate::check(['away' => true], $mon, null, ['at_home' => true]) !== null && RoutineGate::check(['away' => true], $mon, null, ['at_home' => false]) === null && RoutineGate::check(['away' => true], $mon, null, []) === null);
check('others_away: blocks only when someone is known to be home', RoutineGate::check(['others_away' => true], $mon, null, ['others_home' => true]) !== null && RoutineGate::check(['others_away' => true], $mon, null, ['others_home' => null]) === null);
check('normalize keeps known keys, coerces, drops junk', RoutineGate::normalize(['weekdays' => ['1', 9, 3], 'between' => ['07:00', 'x'], 'cooldown_minutes' => '30', 'away' => 'true', 'bogus' => 1]) === ['weekdays' => [1, 3], 'cooldown_minutes' => 30, 'away' => true]);
check('describe reads as words', RoutineGate::describe(['weekdays' => [1, 2], 'between' => ['07:00', '22:00'], 'others_away' => true]) === 'Mon, Tue · 07:00–22:00 · only if nobody else is home');

echo "2) enter / leave from the saved shapes\n";
$home = ['id' => 4, 'type' => 'home', 'shape' => 'circle', 'lat' => 55.0, 'lon' => 12.0, 'radius_m' => 85, 'polygon' => []];
$campus = ['id' => 2, 'type' => 'work', 'shape' => 'polygon', 'lat' => null, 'lon' => null, 'radius_m' => null, 'polygon' => [[55.05, 12.05], [55.05, 12.06], [55.06, 12.06], [55.06, 12.05]]];
$p = static fn (float $lat, float $lon, ?int $acc = 10, ?int $vel = 0): array => ['lat' => $lat, 'lon' => $lon, 'acc' => $acc, 'vel' => $vel];
$r = PlaceTransitions::step(null, [$home], $p(55.0, 12.0));
check('the first accurate point is only a baseline', $r['event'] === null && $r['state'] === ['inside' => true, 'streak' => 0]);
$s = $r['state'];
$r = PlaceTransitions::step($s, [$home], $p(55.01, 12.01));
check('one point outside is not leaving yet', $r['event'] === null && $r['state']['streak'] === 1 && $r['state']['inside'] === true);
$r2 = PlaceTransitions::step($r['state'], [$home], $p(55.0, 12.0));
check('…and a point back inside resets it', $r2['event'] === null && $r2['state']['streak'] === 0);
$r3 = PlaceTransitions::step($r['state'], [$home], $p(55.012, 12.012));
check('two accurate points outside = left', $r3['event'] === 'leave' && $r3['state'] === ['inside' => false, 'streak' => 0]);
check('an inaccurate point says nothing', PlaceTransitions::step($s, [$home], $p(55.5, 12.5, 400)) === ['state' => null, 'event' => null]);
$out = ['inside' => false, 'streak' => 0];
check('arriving: a slow accurate point inside = enter', PlaceTransitions::step($out, [$home], $p(55.0, 12.0, 20, 3))['event'] === 'enter');
check('driving through is not arriving', PlaceTransitions::step($out, [$home], $p(55.0, 12.0, 20, 60)) === ['state' => null, 'event' => null]);
check('a polygon is inside by its own shape (a campus)', PlaceTransitions::step(null, [$campus], $p(55.055, 12.055))['state']['inside'] === true && PlaceTransitions::step(null, [$campus], $p(55.07, 12.055))['state']['inside'] === false);
check('no watched places → nothing', PlaceTransitions::step(null, [], $p(55.0, 12.0)) === ['state' => null, 'event' => null]);

echo "3) the data layer\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE scheduled_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, conversation_id INT, label TEXT, instruction TEXT, frequency TEXT, trigger_type TEXT DEFAULT 'time',
    phrases TEXT, trigger_config TEXT, conditions TEXT, run_mode TEXT DEFAULT 'auto', status TEXT DEFAULT 'active', notify_always INT DEFAULT 0, next_run_at TEXT, run_requested_at TEXT, run_trigger TEXT,
    time_of_day TEXT, day_of_week INT, interval_minutes INT, has_unseen_result INT DEFAULT 0, last_result_title TEXT,
    last_run_at TEXT, last_run_status TEXT, last_summary TEXT, last_run_notified INT DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec('CREATE TABLE routine_place_state (task_id INT, place_id INT, inside INT, streak INT, updated_at TEXT, PRIMARY KEY (task_id, place_id))');
$db->exec("CREATE TABLE routine_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INT, user_id INT, trigger_text TEXT, status TEXT DEFAULT 'pending', created_at TEXT, decided_at TEXT)");
$R = new Routines($db);

$id = $R->create(1, 10, ['label' => 'Leaving', 'instruction' => 'Turn off all lights and set the heating to 17.', 'trigger_type' => 'phrase', 'phrases' => ["I'm leaving", 'jeg smutter', "i'm LEAVING", '  '], 'conditions' => ['weekdays' => [1, 2]], 'run_mode' => 'ask']);
$row = $R->get(1, $id);
check('a new routine is a draft, with cleaned phrases, conditions and mode', $row['status'] === 'draft' && $row['phrases'] === ["I'm leaving", 'jeg smutter'] && $row['conditions'] === ['weekdays' => [1, 2]] && $row['run_mode'] === 'ask');
check('a draft is not live: not found by phrase, not listed active', $R->find(1, "I'm leaving") === null && $R->active(1) === []);
check('another user cannot see or change it', $R->get(2, $id) === null && !$R->setStatus(2, $id, 'active') && !$R->update(2, $id, ['label' => 'x']));
$R->setStatus(1, $id, 'active');
check('Save makes it live: found by exact name, id, phrase, and a sentence containing the phrase', $R->find(1, 'leaving')['id'] === $id && $R->find(1, (string) $id)['id'] === $id && $R->find(1, "Ok, I'M leaving!")['id'] === $id && $R->find(1, 'ok jeg smutter nu')['id'] === $id && $R->find(1, 'what is the weather') === null);
check('update changes only what is given', $R->update(1, $id, ['label' => 'Going out', 'run_mode' => 'auto']) && $R->get(1, $id)['label'] === 'Going out' && $R->get(1, $id)['instruction'] !== '' && $R->get(1, $id)['run_mode'] === 'auto');

check('requestRun asks once; a second ask while one waits is refused', $R->requestRun(1, $id, 'voice: I\'m leaving') === true && $R->requestRun(1, $id, 'again') === false);
check('the waiting run is listed, and exactly one claim wins it', count($R->requested()) === 1 && $R->claimRequest($id) === true && $R->claimRequest($id) === false && $R->requested() === []);
$R->setStatus(1, $id, 'paused');
check('a paused routine cannot be requested', $R->requestRun(1, $id, 'x') === false && $R->find(1, 'leaving') === null);
$R->setStatus(1, $id, 'active');

$pid = $R->create(1, 11, ['label' => 'Left home', 'instruction' => 'Turn off the lights.', 'trigger_type' => 'place', 'trigger_config' => Routines::normalizePlaceTrigger(['event' => 'leave', 'place_types' => ['Home']]), 'status' => 'active']);
$pr = $R->get(1, $pid);
check('a place routine keeps its trigger', $pr['trigger'] === ['event' => 'leave', 'place_ids' => [], 'place_types' => ['home']] && count($R->active(1, 'place')) === 1 && count($R->active(1, 'phrase')) === 1);
check('a place trigger must name a place', Routines::normalizePlaceTrigger(['event' => 'leave']) === null);
check('the trigger reads as words', Routines::describeTrigger($pr) === 'Leaving any home place' && Routines::describeTrigger($row) === 'Say: “I\'m leaving” / “jeg smutter”');

check('place state: none, then saved, then updated in place', $R->placeState($pid, 4) === null && ($R->savePlaceState($pid, 4, true, 0) ?? true) && $R->placeState($pid, 4) === ['inside' => true, 'streak' => 0]
    && ($R->savePlaceState($pid, 4, false, 1) ?? true) && $R->placeState($pid, 4) === ['inside' => false, 'streak' => 1] && (int) $db->query('SELECT COUNT(*) FROM routine_place_state')->fetchColumn() === 1);

$q = $R->createRequest($pid, 1, 'left Home');
check('an ask-first question: one open per routine', $q > 0 && $R->createRequest($pid, 1, 'left Home again') === $q && count($R->pendingRequests(1)) === 1);
check('declining closes it without a run', $R->decideRequest(1, $q, false) === 0 && $R->pendingRequests(1) === [] && $R->requested() === []);
$q2 = $R->createRequest($pid, 1, 'left Home');
check('approving asks for the run', $R->decideRequest(1, $q2, true) === $pid && count($R->requested()) === 1 && $R->requested()[0]['run_trigger'] === 'approved: left Home');
check('a question that is not yours or already answered is refused', $R->decideRequest(2, $q2, true) === null && $R->decideRequest(1, $q2, true) === null);
$q3 = $R->createRequest($id, 1, 'old');
$db->exec("UPDATE routine_requests SET created_at = '2020-01-01 00:00:00' WHERE id = {$q3}");
check('old questions expire', $R->pendingRequests(1) === []);
check('the gate reads the routine\'s own conditions and last run', Routines::gate(['conditions' => ['weekdays' => [6, 7]], 'last_run_at' => null], [], $at('2026-10-19 12:00')) !== null && Routines::gate(['conditions' => [], 'last_run_at' => null]) === null);

echo "4) the tools\n";
$db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT)');
$db->exec("CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, type TEXT DEFAULT 'other', shape TEXT DEFAULT 'circle', lat REAL, lon REAL, radius_m INT, polygon TEXT, created_at TEXT, updated_at TEXT)");
$places = new Places($db);
$places->add(1, ['name' => 'Home', 'type' => 'home', 'shape' => 'circle', 'lat' => 55.0, 'lon' => 12.0, 'radius_m' => 85]);
$tasks = new ScheduledTasks($db);
$convs = new Conversations($db);
$create = new CreateRoutine($R, $tasks, $convs, $places);
$update = new UpdateRoutine($R, $tasks, $places);
$kickLog = sys_get_temp_dir() . '/kachow-kick-' . bin2hex(random_bytes(4));
$run    = new RunRoutine($R, '/nonexistent/runner.php');

$r1 = $create->execute(['name' => 'Away', 'instruction' => 'Turn off all lights and set the heating to 17.', 'phrases' => ["I'm leaving", 'jeg smutter']], 7);
check('create_routine: a phrase routine is saved as a DRAFT and the card comes back', ($r1['created'] ?? false) === true && $r1['status'] === 'draft' && ($r1['_render']['kind'] ?? '') === 'scheduled_tasks'
    && $r1['_render']['items'][0]['routine'] === true && $r1['_render']['items'][0]['status'] === 'draft' && $r1['_render']['items'][0]['schedule'] === 'Say: “I\'m leaving” / “jeg smutter”', json_encode($r1));
check('the card shows it as "when triggered", with no next run', $r1['_render']['items'][0]['next_run'] === null && $r1['routine']['when'] === 'Say: “I\'m leaving” / “jeg smutter”');
check('a routine needs a trigger and an instruction', isset($create->execute(['name' => 'X', 'instruction' => 'y'], 7)['error']) && isset($create->execute(['name' => 'X', 'phrases' => ['x']], 7)['error']));
$r2 = $create->execute(['name' => 'Left home', 'instruction' => 'Turn off the lights.', 'trigger_event' => 'leave', 'trigger_place' => 'home', 'mode' => 'ask', 'conditions' => ['weekdays' => [1, 2, 3, 4, 5], 'others_away' => true], 'save' => true], 1);
check('a place routine resolves the place by name, keeps mode and conditions, and can be saved at once', $r2['status'] === 'active' && $r2['routine']['when'] === 'Leaving Home' && $r2['routine']['mode'] === 'ask'
    && $r2['routine']['conditions'] === 'Mon, Tue, Wed, Thu, Fri · only if nobody else is home', json_encode($r2));
check('an unknown place is a clear error that names the places they have', str_contains($create->execute(['name' => 'X', 'instruction' => 'y', 'trigger_event' => 'leave', 'trigger_place' => 'Gym'], 1)['error'] ?? '', 'Home'));
check('a place type works too', ($create->execute(['name' => 'Office', 'instruction' => 'y', 'trigger_event' => 'enter', 'trigger_place_type' => 'work'], 1)['routine']['when'] ?? '') === 'Arriving at any work place');

// user 7 has no 'Home' place: a place routine there must fail, and routines are user-scoped
check('places are the user\'s own', isset($create->execute(['name' => 'X', 'instruction' => 'y', 'trigger_event' => 'leave', 'trigger_place' => 'Home'], 7)['error']));

$id1 = $r1['id'];
check('a draft does not run by phrase', isset($run->execute(['routine' => "I'm leaving"], 7)['error']));
$u = $update->execute(['routine' => 'Away', 'status' => 'active'], 7);
check('update_routine status active SAVES the draft', ($u['updated'] ?? false) && $R->get(7, $id1)['status'] === 'active');
$rr = $run->execute(['routine' => "ok i'm leaving now", 'said' => "I'm leaving"], 7);
check('run_routine matches the phrase, asks for the run, and says what to tell the user', ($rr['started'] ?? false) === true && $rr['routine'] === 'Away' && $R->get(7, $id1)['run_requested_at'] !== null
    && array_values(array_filter($R->requested(), static fn (array $x): bool => $x['id'] === $id1))[0]['run_trigger'] === "you said: I'm leaving", json_encode($rr));
check('asking again while it waits is not a second run', ($run->execute(['routine' => 'Away'], 7)['started'] ?? true) === false);
$u2 = $update->execute(['routine' => 'Away', 'phrases' => ['bye'], 'instruction' => 'Lights off.', 'mode' => 'ask'], 7);
check('update_routine changes only what is given (phrases REPLACE)', $R->get(7, $id1)['phrases'] === ['bye'] && $R->get(7, $id1)['instruction'] === 'Lights off.' && $R->get(7, $id1)['run_mode'] === 'ask' && $R->get(7, $id1)['label'] === 'Away');
check('a phrase routine cannot be given a place trigger', isset($update->execute(['routine' => 'Away', 'trigger_event' => 'leave', 'trigger_place_type' => 'home'], 7)['error']));
$update->execute(['routine' => 'Away', 'status' => 'paused'], 7);
check('a paused routine can still be found for editing, not for running', $R->get(7, $id1)['status'] === 'paused' && isset($run->execute(['routine' => 'Away'], 7)['error']) && ($update->execute(['routine' => 'Away', 'status' => 'active'], 7)['updated'] ?? false));
check('another user cannot touch it', isset($update->execute(['routine' => 'Away', 'status' => 'cancelled'], 8)['error']) && $R->get(7, $id1)['status'] === 'active');
$update->execute(['routine' => (string) $id1, 'status' => 'cancelled'], 7);
check('cancelled routines are gone from the lists', $R->get(7, $id1)['status'] === 'cancelled' && $R->countAll(7) === 0);
check('voice: run / create / update are voice tools; removing a routine needs a spoken yes', VoiceTools::isAllowed('run_routine') && VoiceTools::isSlow('run_routine') && VoiceTools::isAllowed('create_routine')
    && VoiceTools::needsConfirm('update_routine', ['status' => 'cancelled']) && !VoiceTools::needsConfirm('update_routine', ['status' => 'active']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
