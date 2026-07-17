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
 *   expect — a group that MUST be matched (implies "not the empty fallback")
 *   absent — a group that must NOT be matched (guards spurious narrowing)
 *   empty  — true if the message should match NOTHING (intentional fallback)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Tools\ToolSelector;

/** @var array<int, array{msg:string, expect?:string, absent?:string, empty?:bool}> */
$cases = [
    // ---- cycle (period + mood/energy), EN + DA ----
    ['msg' => 'my period started today', 'expect' => 'cycle'],
    ['msg' => 'I got my period yesterday', 'expect' => 'cycle'],
    ['msg' => 'min menstruation startede i dag', 'expect' => 'cycle'],
    ['msg' => 'hvornår kommer min næste menstruation?', 'expect' => 'cycle'],
    ['msg' => 'what day of my cycle am I on?', 'expect' => 'cycle'],
    ['msg' => 'am I fertile right now?', 'expect' => 'cycle'],
    ['msg' => 'my energy is really low today', 'expect' => 'cycle'],
    ['msg' => 'log my mood as 4', 'expect' => 'cycle'],
    ['msg' => 'mit humør er lavt i dag', 'expect' => 'cycle'],
    ['msg' => 'jeg er helt drænet i dag', 'expect' => 'cycle'],
    ['msg' => 'please recycle the bottles', 'absent' => 'cycle'],
    ['msg' => 'we rode the motorcycle', 'absent' => 'cycle'],

    // ---- shopping / to-do lists ----
    ['msg' => 'add milk to the shopping list', 'expect' => 'shopping'],
    ['msg' => 'tilføj mælk til indkøbslisten', 'expect' => 'shopping'],
    ['msg' => 'cross bread off the list', 'expect' => 'shopping'],
    ['msg' => 'add eggs to the to-do list', 'expect' => 'shopping'],
    ['msg' => 'put it on the todo', 'expect' => 'shopping'],
    ['msg' => 'kryds mælk af på listen', 'expect' => 'shopping'],

    // ---- workouts ----
    ['msg' => 'log 3 sets of bench press', 'expect' => 'workouts'],
    ['msg' => "what's my workout plan today?", 'expect' => 'workouts'],
    ['msg' => 'hvad er min træningsplan i dag?', 'expect' => 'workouts'],
    ['msg' => 'how much did I squat last week?', 'expect' => 'workouts'],

    // ---- calendar ----
    ['msg' => "what's on my calendar tomorrow?", 'expect' => 'calendar'],
    ['msg' => 'am I free friday?', 'expect' => 'calendar'],
    ['msg' => 'hvad har jeg i kalenderen i morgen?', 'expect' => 'calendar'],
    ['msg' => 'book a meeting at 3pm', 'expect' => 'calendar'],

    // ---- email ----
    ['msg' => 'read my latest email', 'expect' => 'email'],
    ['msg' => "reply to Anna's email", 'expect' => 'email'],
    ['msg' => 'skriv en mail til chefen', 'expect' => 'email'],
    ['msg' => 'any unread mail?', 'expect' => 'email'],

    // ---- receipts / expenses ----
    ['msg' => 'log an expense of 200 kr', 'expect' => 'receipts'],
    ['msg' => 'how much have I spent on food?', 'expect' => 'receipts'],
    ['msg' => 'hvad har jeg brugt på mad?', 'expect' => 'receipts'],
    ['msg' => 'export my expenses to csv', 'expect' => 'receipts'],

    // ---- worklog vs worktime ----
    ['msg' => 'log what I did at work today', 'expect' => 'worklog'],
    ['msg' => 'hvad lavede jeg på arbejde i sidste uge?', 'expect' => 'worklog'],
    ['msg' => 'clock me out', 'expect' => 'worktime'],
    ['msg' => 'stempl mig ud', 'expect' => 'worktime'],
    ['msg' => 'how many hours have I worked today?', 'expect' => 'worktime'],

    // ---- weather ----
    ['msg' => "what's the weather tomorrow?", 'expect' => 'weather'],
    ['msg' => 'vejret i morgen?', 'expect' => 'weather'],

    // ---- vinyls ----
    ['msg' => 'what vinyl should I put on?', 'expect' => 'vinyls'],
    ['msg' => 'recommend a record from my collection', 'expect' => 'vinyls'],

    // ---- settings (per-user work calendar) ----
    ['msg' => 'which calendar do you use for my work?', 'expect' => 'settings'],
    ['msg' => 'brug min kalender Vagter til arbejde', 'expect' => 'settings'],

    // ---- connections ----
    ['msg' => 'connect me with Alex', 'expect' => 'connections'],
    ['msg' => 'forbind mig med Alex', 'expect' => 'connections'],

    // ---- dev ideas ----
    ['msg' => 'add this to the backlog', 'expect' => 'devideas'],
    ['msg' => 'gem denne udviklingsidé', 'expect' => 'devideas'],

    // ---- memory / instructions / profile ----
    ["msg" => "remember that I'm allergic to nuts", 'expect' => 'memory'],
    ['msg' => 'husk at jeg er allergiker', 'expect' => 'memory'],
    ['msg' => 'from now on answer in Danish', 'expect' => 'instructions'],
    ['msg' => 'call me Chris', 'expect' => 'profile'],

    // ---- intentional fallback (nothing should match) ----
    ['msg' => 'asdf qwerty zxcv', 'empty' => true],
];

$validGroups = array_flip(ToolSelector::groupNames());
$fail = 0;
$pass = 0;

foreach ($cases as $c) {
    $groups = ToolSelector::matchGroups($c['msg']);
    $errors = [];

    // Guard: fixtures must reference real group names.
    foreach (['expect', 'absent'] as $field) {
        if (isset($c[$field]) && !isset($validGroups[$c[$field]])) {
            $errors[] = "fixture references unknown group '{$c[$field]}'";
        }
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

echo "\nRouting test: {$pass} passed, {$fail} failed (" . count($cases) . " cases).\n";
exit($fail === 0 ? 0 : 1);
