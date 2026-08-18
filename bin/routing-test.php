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
    ['msg' => "show Alex's bench progression", 'expect' => 'workouts'],
    ['msg' => 'vis Alex fremgang i bænkpres', 'expect' => 'workouts'],
    ['msg' => "log Alex's bench: 3 sets of 5 at 60 kg", 'expect' => 'workouts'],
    ['msg' => 'log Alex bænkpres 3x5 med 60 kg', 'expect' => 'workouts'],
    ['msg' => 'mark all my emails as read', 'expect' => 'email'],
    ['msg' => 'markér alle mails som læst', 'expect' => 'email'],
    ['msg' => 'am I getting stronger on squats?', 'expect' => 'workouts'],
    ['msg' => 'how has my deadlift trended over time?', 'expect' => 'workouts'],
    ['msg' => 'bliver jeg stærkere i bænkpres?', 'expect' => 'workouts'],
    ['msg' => 'vis min fremgang i squat', 'expect' => 'workouts'],
    ['msg' => 'squat and backsquat are the same exercise', 'expect' => 'workouts'],
    ['msg' => 'standardise deadlift and dødløft to one name', 'expect' => 'workouts'],
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
    // udlæg (privately paid) — must offer the expense tools too
    ['msg' => 'I paid for the parking myself, log it as an udlæg', 'expect' => 'receipts'],
    ['msg' => 'jeg lagde ud for kontorartikler', 'expect' => 'receipts'],

    // ---- bookkeeping: income / invoices / draws ----
    ['msg' => 'I invoiced the kommune 10000 plus moms', 'expect' => 'bookkeeping'],
    ['msg' => "what's my revenue this quarter?", 'expect' => 'bookkeeping'],
    ['msg' => 'how much have I invoiced this year?', 'expect' => 'bookkeeping'],
    ['msg' => 'which invoices are still outstanding?', 'expect' => 'bookkeeping'],
    ['msg' => 'mark the kommune invoice as paid', 'expect' => 'bookkeeping'],
    ['msg' => 'I paid myself 15000 today', 'expect' => 'bookkeeping'],
    ['msg' => 'jeg har sendt en faktura til kommunen på 8000', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget har jeg faktureret i denne måned?', 'expect' => 'bookkeeping'],
    ['msg' => 'hvem skylder mig penge?', 'expect' => 'bookkeeping'],
    ['msg' => 'jeg hævede 10000 til mig selv', 'expect' => 'bookkeeping'],
    ['msg' => 'hvad er min omsætning i år?', 'expect' => 'bookkeeping'],
    // reimbursing an udlæg — both bookkeeping and receipts carry the tool
    ['msg' => 'jeg har refunderet mit udlæg', 'expect' => 'bookkeeping'],
    ['msg' => 'I sent DSB a 5000 invoice', 'expect' => 'bookkeeping'],
    // report #11: a keyword-less income follow-up after a "vat" turn was routed to
    // receipts-only, so the invoice got booked as an expense. Income+expenses are now
    // coupled — either side offers both toolsets.
    ['msg' => 'i also sent them one on 5k a week ago', 'recent' => "no it's 10k with vat", 'expect' => 'bookkeeping'],
    ['msg' => 'log an expense of 200 kr', 'expect' => 'bookkeeping'],   // coupling: expense turn also offers income tools
    ['msg' => 'show my books', 'expect' => 'bookkeeping'],
    ['msg' => 'open the bookkeeping overview for this quarter', 'expect' => 'bookkeeping'],
    ['msg' => 'vis mit regnskab', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget skal jeg hensætte til skat?', 'expect' => 'bookkeeping'],
    // moms settlement card
    ['msg' => 'how much moms do I owe this quarter?', 'expect' => 'bookkeeping'],
    ['msg' => 'show my momsafregning', 'expect' => 'bookkeeping'],
    ['msg' => 'when is the moms deadline?', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget moms skal jeg betale?', 'expect' => 'bookkeeping'],
    ['msg' => 'vis min momsangivelse for sidste kvartal', 'expect' => 'bookkeeping'],
    // cash position / expected bank balance
    ['msg' => 'how much should be in my bank account?', 'expect' => 'bookkeeping'],
    ['msg' => 'how much money do I actually have?', 'expect' => 'bookkeeping'],
    ['msg' => 'how much am I free to spend?', 'expect' => 'bookkeeping'],
    ['msg' => 'I paid 3450 kr moms to SKAT today', 'expect' => 'bookkeeping'],
    ['msg' => 'log a bank fee of 50 kr', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget står der på kontoen?', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget kan jeg bruge?', 'expect' => 'bookkeeping'],
    ['msg' => 'jeg betalte 3450 i moms', 'expect' => 'bookkeeping'],
    ['msg' => 'jeg har indskudt 10000 i virksomheden', 'expect' => 'bookkeeping'],
    // profit & loss (resultatopgørelse)
    ['msg' => 'show my profit and loss for this year', 'expect' => 'bookkeeping'],
    ['msg' => 'what is my profit this quarter?', 'expect' => 'bookkeeping'],
    ['msg' => 'vis min resultatopgørelse', 'expect' => 'bookkeeping'],
    ['msg' => 'hvad er mit overskud i år?', 'expect' => 'bookkeeping'],
    ['msg' => 'expenses by category this year', 'expect' => 'bookkeeping'],
    // invoice generation + company profile
    ['msg' => 'create an invoice for a client for 5000 plus moms', 'expect' => 'bookkeeping'],
    ['msg' => 'generate an invoice for 10 hours of consulting', 'expect' => 'bookkeeping'],
    ['msg' => 'lav en faktura til en privat kunde', 'expect' => 'bookkeeping'],
    ['msg' => 'set my company details, CVR is 12345678', 'expect' => 'bookkeeping'],
    ['msg' => 'min virksomhed hedder Kachow og mit cvr-nummer er 12345678', 'expect' => 'bookkeeping'],
    // mileage / driving (kørsel)
    ['msg' => 'I drove to the customer today, log it', 'expect' => 'bookkeeping'],
    ['msg' => 'show my mileage deduction', 'expect' => 'bookkeeping'],
    ['msg' => 'how many business driving days do I have left?', 'expect' => 'bookkeeping'],
    ['msg' => 'jeg kørte på arbejde i dag', 'expect' => 'bookkeeping'],
    ['msg' => 'hvor meget kørselsfradrag har jeg?', 'expect' => 'bookkeeping'],
    ['msg' => 'registrér min kørsel til kunden', 'expect' => 'bookkeeping'],

    // ---- worklog vs worktime ----
    ['msg' => 'log what I did at work today', 'expect' => 'worklog'],
    ['msg' => 'hvad lavede jeg på arbejde i sidste uge?', 'expect' => 'worklog'],
    ['msg' => 'export my work log this month', 'expect' => 'worklog'],
    ['msg' => 'clock me out', 'expect' => 'worktime'],
    ['msg' => 'stempl mig ud', 'expect' => 'worktime'],
    ['msg' => 'punch in now', 'expect' => 'worktime'],
    ['msg' => 'how many hours have I worked today?', 'expect' => 'worktime'],
    ['msg' => 'hvor mange timer har jeg arbejdet?', 'expect' => 'worktime'],
    ['msg' => 'show my work hours per day this week', 'expect' => 'worktime'],
    ['msg' => 'how many hours did I work each month?', 'expect' => 'worktime'],
    ['msg' => 'vis mine arbejdstimer per uge', 'expect' => 'worktime'],
    // "how much did I work" totals must hit the CLOCK tools, not the work log (report #16)
    ['msg' => 'how much did I work last week?', 'expect' => 'worktime'],
    ['msg' => 'how much have I worked this week', 'expect' => 'worktime'],

    // ---- weather ----
    ['msg' => "what's the weather tomorrow?", 'expect' => 'weather'],
    ['msg' => 'do I need an umbrella today?', 'expect' => 'weather'],
    ['msg' => 'vejret i morgen?', 'expect' => 'weather'],
    ['msg' => 'hvor koldt bliver det i dag?', 'expect' => 'weather'],
    ['msg' => 'regner det i dag?', 'expect' => 'weather'],
    // Weather stems must not fire inside unrelated (dev-context) words: "regn" in
    // "afregning" (billing), "grader" in "opgradere" (upgrade).
    ['msg' => 'jeg har arbejdet på den nye forbrugsafregningsapp', 'absent' => 'weather'],
    ['msg' => 'jeg skal opgradere appen i dag', 'absent' => 'weather'],

    // ---- vinyls ----
    ['msg' => 'what vinyl should I put on?', 'expect' => 'vinyls'],
    ['msg' => 'recommend a record from my collection', 'expect' => 'vinyls'],
    ['msg' => 'anbefal en plade fra min samling', 'expect' => 'vinyls'],

    // ---- settings (per-user work calendar) ----
    ['msg' => 'which calendar do you use for my work?', 'expect' => 'settings'],
    ['msg' => 'brug min kalender Vagter til arbejde', 'expect' => 'settings'],
    ['msg' => 'what are my settings?', 'expect' => 'settings'],
    // personality / tone dial
    ['msg' => 'turn up your personality to full', 'expect' => 'settings'],
    ['msg' => 'keep your tone neutral', 'expect' => 'settings'],
    ['msg' => 'skru op for personligheden', 'expect' => 'settings'],
    // appearance / theme
    ['msg' => 'switch to the lavender theme', 'expect' => 'settings'],
    ['msg' => 'change the appearance to dark mode', 'expect' => 'settings'],
    ['msg' => 'skift til disco-temaet', 'expect' => 'settings'],
    ['msg' => 'i want to choose theme', 'expect' => 'settings'],       // report #9: must offer the picker
    ['msg' => 'let me pick a look', 'expect' => 'settings'],

    // ---- feedback (developer/admin) ----
    ['msg' => 'any feedback reports?', 'expect' => 'feedback'],
    ['msg' => 'show me the error reports', 'expect' => 'feedback'],
    ['msg' => 'did user report anything to the developer?', 'expect' => 'feedback'],
    ['msg' => 'er der nye fejlrapporter?', 'expect' => 'feedback'],
    ['msg' => 'what did users report', 'expect' => 'feedback'],
    ['msg' => 'turn off thought logging', 'expect' => 'feedback'],
    ['msg' => 'mark report 3 as done', 'expect' => 'feedback'],
    ['msg' => 'hvad har brugerne rapporteret?', 'expect' => 'feedback'],

    // ---- reminders ----
    ['msg' => 'remind me to call mum at 18:00', 'expect' => 'reminders'],
    ['msg' => 'set a reminder for tomorrow at 9', 'expect' => 'reminders'],
    ['msg' => 'what reminders do I have?', 'expect' => 'reminders'],
    ['msg' => 'cancel my reminder', 'expect' => 'reminders'],
    ['msg' => 'mind mig om at flytte vasketøjet om 2 timer', 'expect' => 'reminders'],
    ['msg' => 'husk mig på at ringe i morgen', 'expect' => 'reminders'],

    // ---- connections ----
    ['msg' => 'connect me with Alex', 'expect' => 'connections'],
    ['msg' => 'forbind mig med Alex', 'expect' => 'connections'],
    ['msg' => 'stop sharing my workouts with Alex', 'expect' => 'connections'],
    // Granting cross-user permission (report #8: "give X permission to log for me" missed
    // update_connection_sharing, so the assistant wrongly insisted it had no such tool).
    ['msg' => 'Giv Alex lov til at logge træning for mig', 'expect' => 'connections'],
    ['msg' => 'give Alex permission to log workouts for me', 'expect' => 'connections'],
    ['msg' => 'lad Alex logge for mig', 'expect' => 'connections'],
    ['msg' => 'log a workout on my behalf', 'expect' => 'connections'],

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
