<?php

declare(strict_types=1);

/**
 * Tool-routing self-test. Asserts that representative messages (English + Danish)
 * route to the expected ToolSelector group — and, crucially, do NOT fall through to
 * the all-tools fallback. Turns the recurring "a Danish word wasn't covered" bug into
 * a regression guard: when a real message mis-routes, add it here so it can't recur.
 *
 *   php bin/routing-test.php        # runs all cases; exit 0 = pass, 1 = failure
 *
 * No DB / API / network needed — pure keyword logic.
 *
 * Fixture fields:
 *   msg    — the user message
 *   recent — optional prior-turn context (routes keyword-less follow-ups)
 *   expect — a group that MUST be matched (implies "not the empty fallback")
 *   absent — a group that must NOT be matched (guards spurious narrowing)
 *   empty  — true if the message should match NOTHING (intentional fallback)
 *
 * A coverage check at the end fails if any group has no positive fixture.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\ToolSelector;

/** @var array<int, array{msg:string, recent?:string, expect?:string, absent?:string, empty?:bool}> */
$cases = [
    // ---- cycle: periods ----
    ['msg' => 'my period started today', 'expect' => 'cycle'],
    ['msg' => 'I got my period yesterday', 'expect' => 'cycle'],
    ['msg' => 'log my period from 12 July', 'expect' => 'cycle'],
    ['msg' => 'remove the last period I logged', 'expect' => 'cycle'],
    ['msg' => 'min menstruation startede i dag', 'expect' => 'cycle'],
    ['msg' => 'hvornår kommer min næste menstruation?', 'expect' => 'cycle'],
    ['msg' => 'min periode begyndte i går', 'expect' => 'cycle'],
    ['msg' => 'what day of my cycle am I on?', 'expect' => 'cycle'],
    ['msg' => 'am I fertile right now?', 'expect' => 'cycle'],
    ['msg' => 'er jeg frugtbar nu?', 'expect' => 'cycle'],
    ['msg' => 'when is my ovulation?', 'expect' => 'cycle'],
    ['msg' => 'hvornår har jeg ægløsning?', 'expect' => 'cycle'],
    // ---- cycle: mood / energy ----
    ['msg' => 'my energy is really low today', 'expect' => 'cycle'],
    ['msg' => 'log my mood as 4', 'expect' => 'cycle'],
    ['msg' => 'I feel exhausted', 'expect' => 'cycle'],
    ['msg' => 'mit humør er lavt i dag', 'expect' => 'cycle'],
    ['msg' => 'jeg er helt drænet i dag', 'expect' => 'cycle'],
    ['msg' => 'jeg har masser af energi', 'expect' => 'cycle'],
    // ---- cycle: anti-spurious ----
    ['msg' => 'please recycle the bottles', 'absent' => 'cycle'],
    ['msg' => 'we rode the motorcycle', 'absent' => 'cycle'],

    // ---- shopping / to-do lists ----
    ['msg' => 'add milk to the shopping list', 'expect' => 'shopping'],
    ['msg' => 'we need bread and eggs', 'expect' => 'shopping'],
    ['msg' => 'cross bread off the list', 'expect' => 'shopping'],
    ['msg' => 'add eggs to the to-do list', 'expect' => 'shopping'],
    ['msg' => 'put it on the todo', 'expect' => 'shopping'],
    ['msg' => 'tilføj mælk til indkøbslisten', 'expect' => 'shopping'],
    ['msg' => 'kryds mælk af på listen', 'expect' => 'shopping'],
    ['msg' => 'vi mangler smør', 'expect' => 'shopping'],

    // ---- wishlist (distinct from shopping) ----
    ['msg' => 'add a drone to my wishlist', 'expect' => 'wishlist'],
    ['msg' => 'gift ideas for my mum', 'expect' => 'wishlist'],
    ['msg' => 'tilføj noget til min ønskeliste', 'expect' => 'wishlist'],
    ['msg' => 'jeg vil gerne have en gave-idé', 'expect' => 'wishlist'],

    // ---- workouts ----
    ['msg' => 'log 3 sets of bench press', 'expect' => 'workouts'],
    ['msg' => "what's my workout plan today?", 'expect' => 'workouts'],
    ['msg' => 'hvad er min træningsplan i dag?', 'expect' => 'workouts'],
    ['msg' => 'how much did I squat last week?', 'expect' => 'workouts'],
    ['msg' => 'how much did Alex squat last week?', 'expect' => 'workouts'],
    ['msg' => 'jeg løftede 100 kg i dødløft', 'expect' => 'workouts'],
    ['msg' => 'show my bench progression', 'expect' => 'workouts'],
    ['msg' => 'am I getting stronger on squats?', 'expect' => 'workouts'],
    ['msg' => 'how has my deadlift trended over time?', 'expect' => 'workouts'],
    ['msg' => 'bliver jeg stærkere i bænkpres?', 'expect' => 'workouts'],
    ['msg' => 'vis min fremgang i squat', 'expect' => 'workouts'],
    ['msg' => 'I work as a programmer', 'absent' => 'workouts'],

    // ---- calendar ----
    ['msg' => "what's on my calendar tomorrow?", 'expect' => 'calendar'],
    ['msg' => 'am I free friday?', 'expect' => 'calendar'],
    ['msg' => 'book a meeting at 3pm', 'expect' => 'calendar'],
    ['msg' => 'hvad har jeg i kalenderen i morgen?', 'expect' => 'calendar'],
    ['msg' => 'har jeg et møde på fredag?', 'expect' => 'calendar'],
    ['msg' => "let's meet at 5 o'clock", 'expect' => 'calendar', 'absent' => 'worktime'],

    // ---- email ----
    ['msg' => 'read my latest email', 'expect' => 'email'],
    ['msg' => "reply to Anna's email", 'expect' => 'email'],
    ['msg' => 'any unread mail?', 'expect' => 'email'],
    ['msg' => 'skriv en mail til chefen', 'expect' => 'email'],
    ['msg' => 'svar på mailen fra Anna', 'expect' => 'email'],
    ['msg' => 'tjek min indbakke', 'expect' => 'email'],

    // ---- receipts / expenses ----
    ['msg' => 'log an expense of 200 kr', 'expect' => 'receipts'],
    ['msg' => 'how much have I spent on food?', 'expect' => 'receipts'],
    ['msg' => 'export my expenses to csv', 'expect' => 'receipts'],
    ['msg' => 'hvad har jeg brugt på mad?', 'expect' => 'receipts'],
    ['msg' => 'her er en kvittering fra Netto', 'expect' => 'receipts'],

    // ---- worklog vs worktime ----
    ['msg' => 'log what I did at work today', 'expect' => 'worklog'],
    ['msg' => 'hvad lavede jeg på arbejde i sidste uge?', 'expect' => 'worklog'],
    ['msg' => 'export my work log this month', 'expect' => 'worklog'],
    ['msg' => 'clock me out', 'expect' => 'worktime'],
    ['msg' => 'stempl mig ud', 'expect' => 'worktime'],
    ['msg' => 'punch in now', 'expect' => 'worktime'],
    ['msg' => 'how many hours have I worked today?', 'expect' => 'worktime'],
    ['msg' => 'hvor mange timer har jeg arbejdet?', 'expect' => 'worktime'],

    // ---- weather ----
    ['msg' => "what's the weather tomorrow?", 'expect' => 'weather'],
    ['msg' => 'do I need an umbrella today?', 'expect' => 'weather'],
    ['msg' => 'vejret i morgen?', 'expect' => 'weather'],
    ['msg' => 'hvor koldt bliver det i dag?', 'expect' => 'weather'],

    // ---- vinyls ----
    ['msg' => 'what vinyl should I put on?', 'expect' => 'vinyls'],
    ['msg' => 'recommend a record from my collection', 'expect' => 'vinyls'],
    ['msg' => 'anbefal en plade fra min samling', 'expect' => 'vinyls'],

    // ---- settings (per-user work calendar) ----
    ['msg' => 'which calendar do you use for my work?', 'expect' => 'settings'],
    ['msg' => 'brug min kalender Vagter til arbejde', 'expect' => 'settings'],
    ['msg' => 'what are my settings?', 'expect' => 'settings'],

    // ---- connections ----
    ['msg' => 'connect me with Alex', 'expect' => 'connections'],
    ['msg' => 'forbind mig med Alex', 'expect' => 'connections'],
    ['msg' => 'stop sharing my workouts with Alex', 'expect' => 'connections'],

    // ---- admin (invites) ----
    ['msg' => 'invite Alex to Kachow', 'expect' => 'admin'],
    ['msg' => 'opret en konto til Alex', 'expect' => 'admin'],

    // ---- dev ideas ----
    ['msg' => 'add this to the backlog', 'expect' => 'devideas'],
    ['msg' => 'for later: a dark mode', 'expect' => 'devideas'],
    ['msg' => 'gem denne udviklingsidé', 'expect' => 'devideas'],

    // ---- memory / instructions / profile ----
    ["msg" => "what do you know about me?", 'expect' => 'memory'],
    ['msg' => 'husk at jeg er allergiker', 'expect' => 'memory'],
    ['msg' => 'from now on answer in Danish', 'expect' => 'instructions'],
    ['msg' => 'always use metric units', 'expect' => 'instructions'],
    ['msg' => 'fra nu af, svar kort', 'expect' => 'instructions'],
    ['msg' => 'call me Chris', 'expect' => 'profile'],
    ['msg' => 'jeg hedder Alex', 'expect' => 'profile'],

    // ---- follow-ups that lean on recent context ----
    ['msg' => 'and tomorrow?', 'recent' => "what's the weather like today", 'expect' => 'weather'],
    ['msg' => 'and Alex?', 'recent' => 'how much did I bench press', 'expect' => 'workouts'],

    // ---- intentional fallback (nothing should match) ----
    ['msg' => 'asdf qwerty zxcv', 'empty' => true],
    ['msg' => 'hmm let me think about that', 'empty' => true],
];

$validGroups = array_flip(ToolSelector::groupNames());
$covered     = [];
$fail = 0;
$pass = 0;

foreach ($cases as $c) {
    $groups = ToolSelector::matchGroups($c['msg'], $c['recent'] ?? '');
    $errors = [];

    foreach (['expect', 'absent'] as $field) {
        if (isset($c[$field]) && !isset($validGroups[$c[$field]])) {
            $errors[] = "fixture references unknown group '{$c[$field]}'";
        }
    }
    if (isset($c['expect'])) {
        $covered[$c['expect']] = true;
    }

    if (!empty($c['empty']) && $groups !== []) {
        $errors[] = 'expected fallback (no group) but matched [' . implode(', ', $groups) . ']';
    }
    if (isset($c['expect']) && !in_array($c['expect'], $groups, true)) {
        $errors[] = "expected group '{$c['expect']}' but got [" . implode(', ', $groups) . ']';
    }
    if (isset($c['absent']) && in_array($c['absent'], $groups, true)) {
        $errors[] = "group '{$c['absent']}' should NOT match, but did [" . implode(', ', $groups) . ']';
    }

    if ($errors === []) {
        $pass++;
    } else {
        $fail++;
        fwrite(STDERR, "FAIL: \"{$c['msg']}\"\n");
        foreach ($errors as $e) {
            fwrite(STDERR, "      - {$e}\n");
        }
    }
}

// Coverage meta-check: every group must have at least one positive fixture.
$missing = array_values(array_diff(ToolSelector::groupNames(), array_keys($covered)));
if ($missing !== []) {
    $fail++;
    fwrite(STDERR, 'COVERAGE: no fixture covers group(s): ' . implode(', ', $missing) . "\n");
}

echo "\nRouting test: {$pass} passed, {$fail} failed (" . count($cases) . " cases, "
    . count($covered) . '/' . count($validGroups) . " groups covered).\n";
exit($fail === 0 ? 0 : 1);
