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
use App\Notify\RoutinePlaceRunner;
use App\Support\BackgroundRun;
use App\Support\Household;
use App\Voice\VoiceTools;
use App\Support\PlaceTransitions;
use App\Support\RoutineGate;
use App\Support\RoutineSteps;
use App\Support\SensorTrigger;
use App\Notify\RoutineSensorRunner;
use App\Tools\Tool;
use App\Tools\UndoRoutine;

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
    last_run_at TEXT, last_run_status TEXT, last_summary TEXT, last_run_notified INT DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    steps TEXT, undo_steps TEXT, undo_at TEXT)");
$db->exec('CREATE TABLE routine_sensor_state (task_id INT PRIMARY KEY, matched INT, last_value TEXT, updated_at TEXT)');
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

echo "5) household and background runs\n";
check('nobody could be judged → unknown; anyone known home → true; all known away → false', Household::summarize([]) === null && Household::summarize([null, null]) === null
    && Household::summarize([false, true, null]) === true && Household::summarize([false, null]) === false);
check('a background kick needs an existing script (and exec); otherwise it is a quiet no-op', BackgroundRun::kick('/nonexistent/script.php') === false);

echo "6) place routines from a stream of positions\n";
$u = 3;
$places->add($u, ['name' => 'Flat', 'type' => 'home', 'shape' => 'circle', 'lat' => 56.0, 'lon' => 13.0, 'radius_m' => 85]);
$campusPoly = $places->add($u, ['name' => 'Campus', 'type' => 'work', 'shape' => 'polygon', 'polygon' => [[56.05, 13.05], [56.05, 13.06], [56.06, 13.06], [56.06, 13.05]]]);
$leave = $R->create($u, 30, ['label' => 'Left home', 'instruction' => 'Lights off.', 'trigger_type' => 'place', 'trigger_config' => Routines::normalizePlaceTrigger(['event' => 'leave', 'place_types' => ['home']]), 'status' => 'active']);
$arrive = $R->create($u, 31, ['label' => 'At campus', 'instruction' => 'Say hi.', 'trigger_type' => 'place', 'trigger_config' => Routines::normalizePlaceTrigger(['event' => 'enter', 'place_ids' => [$campusPoly['id']]]), 'status' => 'active']);
$kicks = 0;
$now = 1_800_000_000;
$runner = new RoutinePlaceRunner($R, $places, static function () use (&$kicks): void { $kicks++; }, $now);
$pt = static fn (int $ago, float $lat, float $lon, ?int $acc = 10, ?int $vel = 0): array => ['ts' => $now - $ago, 'lat' => $lat, 'lon' => $lon, 'acc' => $acc, 'vel' => $vel];
check('at home: only the baseline is stored, nothing starts', $runner->onPoints($u, [$pt(600, 56.0, 13.0)]) === [] && $kicks === 0);
check('one point outside is not leaving', $runner->onPoints($u, [$pt(480, 56.01, 13.01)]) === []);
$got = $runner->onPoints($u, [$pt(360, 56.012, 13.012)]);
check('the second accurate point outside = left: the routine is requested and the runner started', $got === [$leave] && $kicks === 1
    && array_values(array_filter($R->requested(), static fn (array $x): bool => $x['id'] === $leave))[0]['run_trigger'] === 'you left Flat');
check('no second run while the first waits, and none for further points outside', $runner->onPoints($u, [$pt(240, 56.02, 13.02), $pt(120, 56.03, 13.03)]) === []);
$R->claimRequest($leave);
check('driving into the campus polygon fast is not arriving; a slow accurate point in it is', $runner->onPoints($u, [$pt(100, 56.055, 13.055, 10, 70)]) === [] && $runner->onPoints($u, [$pt(90, 56.055, 13.055, 10, 4)]) === [$arrive]);
check('arriving home does not start a LEAVE routine; leaving again later does', $runner->onPoints($u, [$pt(80, 56.0, 13.0, 10, 3)]) === [] && $runner->onPoints($u, [$pt(70, 56.02, 13.02), $pt(60, 56.021, 13.021)]) === [$leave]);
$R->claimRequest($leave);
$old = new RoutinePlaceRunner($R, $places, static function () use (&$kicks): void { $kicks++; }, $now + 7200);
check('positions older than half an hour update the state but start nothing', $old->onPoints($u, [$pt(0, 56.0, 13.0, 10, 3), $pt(-1, 56.02, 13.02), $pt(-2, 56.021, 13.021)]) === []);
check('a user with no place routines costs nothing', $runner->onPoints(99, [$pt(10, 56.0, 13.0)]) === []);


echo "7) recorded steps, replay and undo\n";
$w = static fn (string $path, array $body, bool $ok = true): array => ['name' => 'home_device_call', 'args' => ['device' => 'hue', 'method' => 'PUT', 'path' => $path, 'body' => $body], 'ok' => $ok];
$g = static fn (string $path): array => ['name' => 'home_device_call', 'args' => ['device' => 'hue', 'method' => 'GET', 'path' => $path], 'ok' => true];
$calls = [$g('/clip/v2/resource/light'), $w('/clip/v2/resource/light/aa', ['on' => ['on' => false]]), $w('/clip/v2/resource/grouped_light/bb', ['on' => ['on' => false]]), ['name' => 'notify_me', 'args' => ['title' => 't', 'body' => 'b'], 'ok' => true]];
$steps = RoutineSteps::record($calls);
check('a run of only home-device writes is recorded (lookups and the notification dropped)', $steps !== null && count($steps) === 2 && $steps[0]['path'] === '/clip/v2/resource/light/aa' && $steps[1]['body'] === ['on' => ['on' => false]]);
check('any other tool in the run means judgement: nothing is recorded', RoutineSteps::record(array_merge($calls, [['name' => 'get_current_weather', 'args' => [], 'ok' => true]])) === null);
check('a failed write, or no write at all, records nothing', RoutineSteps::record([$w('/clip/v2/resource/light/aa', ['on' => ['on' => true]], false)]) === null && RoutineSteps::record([$g('/clip/v2/resource/light')]) === null && RoutineSteps::record([]) === null);
check('an instruction that decides something is never frozen', RoutineSteps::conditional('If the kitchen light is on, turn it off') && RoutineSteps::conditional('Hvis det er mørkt, tænd lyset') && !RoutineSteps::conditional('Turn off all lights and set the heating to 17.'));

// a fake Hue bridge: two lights, one on at 80 %, one off
$bridge = ['aa' => ['on' => true, 'bri' => 80.0, 'mirek' => 300], 'bb' => ['on' => false, 'bri' => 20.0, 'mirek' => null]];
$log = [];
$fake = static function (string $tool, array $args) use (&$bridge, &$log): array {
    $log[] = $args['method'] . ' ' . $args['path'];
    if (!preg_match('#/(?:light|grouped_light)/(\w+)$#', $args['path'], $m)) {
        return ['http_status' => 200, 'response' => ['data' => []]];
    }
    $id = $m[1];
    if ($id === 'zz') {
        return ['error' => 'The home agent could not complete that: 404'];
    }
    if ($args['method'] === 'GET') {
        $l    = $bridge[$id];
        $item = ['on' => ['on' => $l['on']], 'dimming' => ['brightness' => $l['bri']]];
        if ($l['mirek'] !== null) {
            $item['color_temperature'] = ['mirek' => $l['mirek']];
        }
        return ['http_status' => 200, 'response' => ['data' => [$item]]];
    }
    if (isset($args['body']['on']['on'])) {
        $bridge[$id]['on'] = $args['body']['on']['on'];
    }
    return ['http_status' => 200, 'response' => ['data' => [['rid' => $id]]]];
};
$off = [['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/aa', 'body' => ['on' => ['on' => false]]], ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/bb', 'body' => ['on' => ['on' => false]]],
        ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/scene/s1', 'body' => ['recall' => ['action' => 'active']]]];
$res = RoutineSteps::replay($off, $fake);
check('replay runs every step straight at the bridge', $res['ok'] && $res['done'] === 3 && $bridge['aa']['on'] === false);
check('…and keeps what puts things back (newest first); a scene cannot be restored', count($res['undo']) === 2 && $res['unrestorable'] === 1 && $res['undo'][1]['path'] === '/clip/v2/resource/light/aa'
    && $res['undo'][1]['body'] === ['on' => ['on' => true], 'dimming' => ['brightness' => 80.0], 'color_temperature' => ['mirek' => 300]] && $res['undo'][0]['body'] === ['on' => ['on' => false], 'dimming' => ['brightness' => 20.0]]);
$bridge['bb']['on'] = true;   // something else switched it on meanwhile
$back = RoutineSteps::undo($res['undo'], $fake);
check('undo puts each light back the way it was before the run', $back === ['done' => 2, 'failed' => 0] && $bridge['aa']['on'] === true && $bridge['bb']['on'] === false);
$bad = RoutineSteps::replay([$off[0], ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/zz', 'body' => ['on' => ['on' => false]]]], $fake);
check('a failing step stops the replay, says why, and still lets the part that ran be undone', !$bad['ok'] && $bad['done'] === 1 && str_contains((string) $bad['error'], '404') && count($bad['undo']) === 1);
check('unreachable is told apart from a stale recording', RoutineSteps::unreachable('The home agent (Pico) is offline — last contact 900 seconds ago.') && RoutineSteps::unreachable('No answer from the home agent in 12 seconds') && !RoutineSteps::unreachable('The home agent could not complete that: 404'));

$sid = $R->create(1, 40, ['label' => 'Lights off', 'instruction' => 'Turn off all lights.', 'trigger_type' => 'phrase', 'phrases' => ['lights off'], 'status' => 'active']);
check('a routine starts with no steps; saving them keeps them', $R->get(1, $sid)['steps'] === null && ($R->saveSteps($sid, $off) ?? true) && count($R->get(1, $sid)['steps']) === 3);
$R->saveUndo($sid, $res['undo']);
check('undo is kept with the routine, taken once, and only while fresh', count($R->lastUndoable(1)['undo_steps']) === 2 && count($R->takeUndo(1, $sid)) === 2 && $R->takeUndo(1, $sid) === [] && $R->lastUndoable(1) === null);
$R->saveUndo($sid, $res['undo']);
$db->exec("UPDATE scheduled_tasks SET undo_at = '2020-01-01 00:00:00' WHERE id = {$sid}");
check('a day-old undo is gone', $R->takeUndo(1, $sid) === [] && $R->lastUndoable(1) === null);
$R->update(1, $sid, ['instruction' => 'Turn off all lights.']);
check('the same instruction keeps the steps; a changed one forgets them', $R->get(1, $sid)['steps'] !== null && $R->update(1, $sid, ['instruction' => 'Turn off the hall light.']) && $R->get(1, $sid)['steps'] === null);

$R->saveSteps($sid, $off);
$R->saveUndo($sid, $res['undo']);
$bridge = ['aa' => ['on' => false, 'bri' => 80.0, 'mirek' => 300], 'bb' => ['on' => false, 'bri' => 20.0, 'mirek' => null]];
$home = new class ($fake) implements Tool {
    public function __construct(private $fake) {}
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return ''; }
    public function parameters(): array { return []; }
    public function execute(array $arguments, int $userId): array { return ($this->fake)('home_device_call', $arguments); }
};
$undoTool = new UndoRoutine($R, $home);
$u1 = $undoTool->execute([], 1);
check('"undo that" with no name undoes the newest run', $u1['undone'] === true && $u1['restored'] === 2 && $u1['routine'] === 'Lights off');
$u2 = $undoTool->execute([], 1);
check('and says so when there is nothing left to undo', $u2['undone'] === false && $undoTool->execute(['routine' => 'no such'], 1)['undone'] === false);

echo "8) ask first for recurring tasks\n";
$tid = $db->exec("INSERT INTO scheduled_tasks (user_id, conversation_id, label, instruction, frequency, status, notify_always, next_run_at) VALUES (1, 50, 'Morning heat', 'Heat to 21.', 'daily', 'active', 1, '2026-10-20 05:00:00')") ? (int) $db->lastInsertId() : 0;
$oid = (int) ($db->exec("INSERT INTO scheduled_tasks (user_id, conversation_id, label, instruction, frequency, status, notify_always, next_run_at) VALUES (1, 51, 'One-off', 'x', 'once', 'active', 1, '2026-10-20 05:00:00')") ? $db->lastInsertId() : 0);
check('a recurring time task can be set to ask first, and back', $R->setRunMode(1, $tid, 'ask') && $R->getAny(1, $tid)['run_mode'] === 'ask' && $R->setRunMode(1, $tid, 'auto') && $R->getAny(1, $tid)['run_mode'] === 'auto');
check('a one-off cannot (its slot is gone once claimed); nor another user\'s task, nor a made-up mode', !$R->setRunMode(1, $oid, 'ask') && !$R->setRunMode(2, $tid, 'ask') && !$R->setRunMode(1, $tid, 'maybe'));
$R->setRunMode(1, $tid, 'ask');
$qid = $R->createRequest($tid, 1, 'scheduled time');
check('the question for a time task can be approved and then runs as asked-for', $R->decideRequest(1, $qid, true) === $tid && array_values(array_filter($R->requested(), static fn (array $x): bool => $x['id'] === $tid))[0]['run_trigger'] === 'approved: scheduled time');
$R->claimRequest($tid);

echo "9) sensor triggers\n";
check('names are one key however they are typed', SensorTrigger::key('Kitchen light') === 'kitchen_light' && SensorTrigger::key(' kitchen-LIGHT ') === 'kitchen_light' && SensorTrigger::key('Køkken') === 'koekken');
$below = SensorTrigger::normalize(['sensor' => 'Kitchen light', 'op' => 'below', 'value' => '50']);
check('a numeric trigger is cleaned', $below === ['sensor' => 'kitchen_light', 'op' => 'below', 'value' => 50.0] && SensorTrigger::describe($below) === 'Kitchen light below 50');
$open = SensorTrigger::normalize(['sensor' => 'window', 'value' => 'Open']);
check('a state trigger defaults to "is"', $open === ['sensor' => 'window', 'op' => 'is', 'value' => 'open'] && SensorTrigger::describe($open) === 'Window is open');
check('nonsense is refused', SensorTrigger::normalize(['sensor' => '', 'value' => 1]) === null && SensorTrigger::normalize(['sensor' => 'x']) === null && SensorTrigger::normalize(['sensor' => 'x', 'op' => 'below', 'value' => 'open']) === null);
check('matching: below, above, equals, and a state in any case', SensorTrigger::matches($below, 49.9) && !SensorTrigger::matches($below, 50) && SensorTrigger::matches(['op' => 'above', 'value' => 10.0], 11)
    && SensorTrigger::matches(['op' => 'is', 'value' => 5.0], '5') && SensorTrigger::matches($open, 'OPEN') && !SensorTrigger::matches($open, 'closed'));

$sk = $R->create(1, 60, ['label' => 'Dusk lights', 'instruction' => 'Turn on the kitchen light.', 'trigger_type' => 'sensor', 'trigger_config' => $below, 'status' => 'active']);
$kicked = 0;
$sr = new RoutineSensorRunner($R, static function () use (&$kicked): void { $kicked++; });
check('the first reading is only a baseline, even when it already matches', $sr->onReading(1, 'kitchen light', 30) === [] && $kicked === 0 && $R->sensorState($sk) === true);
check('staying below does not fire again', $sr->onReading(1, 'kitchen_light', 20) === []);
check('rising above, then dropping below fires once', $sr->onReading(1, 'kitchen_light', 80) === [] && $sr->onReading(1, 'kitchen_light', 40) === [$sk] && $kicked === 1
    && array_values(array_filter($R->requested(), static fn (array $x): bool => $x['id'] === $sk))[0]['run_trigger'] === 'sensor: Kitchen light below 50');
$R->claimRequest($sk);
check('another sensor, another user, or a paused routine is not touched', $sr->onReading(1, 'garden', 1) === [] && $sr->onReading(2, 'kitchen_light', 1) === [] && $R->sensorState($sk) === true);
check('it reads as words on the card', Routines::describeTrigger($R->get(1, $sk)) === 'When kitchen light below 50' && count($R->active(1, 'sensor')) === 1);
$res = (new CreateRoutine($R, new ScheduledTasks($db), new Conversations($db), $places))->execute(['name' => 'Window open', 'instruction' => 'Tell me.', 'trigger_sensor' => 'window', 'trigger_sensor_op' => 'is', 'trigger_sensor_value' => 'open', 'save' => true], 1);
check('create_routine builds a sensor routine', ($res['created'] ?? false) === true && $R->get(1, $res['id'])['trigger_type'] === 'sensor' && $R->get(1, $res['id'])['trigger']['value'] === 'open');
$res = (new CreateRoutine($R, new ScheduledTasks($db), new Conversations($db), $places))->execute(['name' => 'Bad', 'instruction' => 'x', 'trigger_sensor' => 'window'], 1);
check('…and refuses a sensor with no value', isset($res['error']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
