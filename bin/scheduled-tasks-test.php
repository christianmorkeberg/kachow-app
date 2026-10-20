<?php

declare(strict_types=1);

/**
 * ScheduledTasks unit tests — pure logic (nextRunAtUtc) + an in-memory SQLite check of
 * claimAndReschedule()'s atomicity. No network, no Gemini: php bin/scheduled-tasks-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ScheduledTasks;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/scheduled-tasks-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$tz  = new DateTimeZone('Europe/Copenhagen');
$utc = new DateTimeZone('UTC');
/** UTC DateTimeImmutable -> "H:i" in Europe/Copenhagen. */
$localTime = static fn (DateTimeImmutable $d): string => $d->setTimezone($tz)->format('H:i');

echo "1) hourly: next run is exactly +1h from \$after\n";
$after = new DateTimeImmutable('2026-10-13 12:00:00', $utc);
$next  = ScheduledTasks::nextRunAtUtc('hourly', null, null, $after);
check('exactly 1 hour later', $next->getTimestamp() - $after->getTimestamp() === 3600, (string) ($next->getTimestamp() - $after->getTimestamp()));

echo "2) daily: time_of_day still ahead today -> fires TODAY\n";
$after = new DateTimeImmutable('2026-10-13 04:00:00', $utc); // 06:00 local (CEST, UTC+2) — before 08:00
$next  = ScheduledTasks::nextRunAtUtc('daily', '08:00', null, $after);
check('same calendar day (local)', $next->setTimezone($tz)->format('Y-m-d') === '2026-10-13', $next->format('c'));
check('local time is 08:00', $localTime($next) === '08:00', $localTime($next));

echo "3) daily: time_of_day already passed today -> rolls to TOMORROW\n";
$after = new DateTimeImmutable('2026-10-13 12:00:00', $utc); // 14:00 local, past 08:00
$next  = ScheduledTasks::nextRunAtUtc('daily', '08:00', null, $after);
check('rolled to the next day (local)', $next->setTimezone($tz)->format('Y-m-d') === '2026-10-14', $next->format('c'));
check('local time still 08:00', $localTime($next) === '08:00', $localTime($next));

echo "4) daily: omitted time_of_day defaults to 08:00\n";
$after = new DateTimeImmutable('2026-10-13 00:00:00', $utc);
$next  = ScheduledTasks::nextRunAtUtc('daily', null, null, $after);
check('defaults to 08:00 local', $localTime($next) === '08:00', $localTime($next));

echo "5) weekly: lands on the requested ISO weekday, at the right local time\n";
// 2026-10-13 is a Tuesday (ISO 2); ask for Friday (5).
$after = new DateTimeImmutable('2026-10-13 06:00:00', $utc);
$next  = ScheduledTasks::nextRunAtUtc('weekly', '09:00', 5, $after);
check('is a Friday', (int) $next->setTimezone($tz)->format('N') === 5, $next->format('c'));
check('local time is 09:00', $localTime($next) === '09:00', $localTime($next));
check('is in the future', $next->getTimestamp() > $after->getTimestamp(), $next->format('c'));

echo "6) weekly: today IS the target weekday but the time already passed -> next WEEK, not today\n";
// 2026-10-13 (Tuesday, ISO 2), ask for Tuesday at 08:00, but \$after is already 14:00 local.
$after = new DateTimeImmutable('2026-10-13 12:00:00', $utc);
$next  = ScheduledTasks::nextRunAtUtc('weekly', '08:00', 2, $after);
check('exactly 7 days later', $next->setTimezone($tz)->format('Y-m-d') === '2026-10-20', $next->format('c'));
check('local time is 08:00', $localTime($next) === '08:00', $localTime($next));

echo "7) DST boundary (Denmark ends DST 2026-10-25): local wall-clock time holds steady across it\n";
// Anchor on 2026-10-24 08:00 local (still CEST, UTC+2) -> next daily run is 2026-10-25
// 08:00 local, but that date is AFTER the fallback to CET (UTC+1) -- the computation must
// still land on 08:00 LOCAL, not 08:00-still-at-the-old-UTC-offset (which would read 07:00
// local the next day if the DST shift were ignored).
$after = new DateTimeImmutable('2026-10-24 06:00:00', $utc); // 08:00 local, CEST
$next  = ScheduledTasks::nextRunAtUtc('daily', '08:00', null, $after);
check('rolled to 2026-10-25', $next->setTimezone($tz)->format('Y-m-d') === '2026-10-25', $next->format('c'));
check('local time is still 08:00 despite the DST fallback', $localTime($next) === '08:00', $localTime($next));

echo "8) claimAndReschedule() — atomic: a second claim before the new next_run_at fails\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// ScheduledTasks' SQL targets MySQL (UTC_TIMESTAMP()); shim it for this SQLite test.
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db->exec("CREATE TABLE scheduled_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, conversation_id INTEGER NOT NULL,
    label TEXT NOT NULL, instruction TEXT NOT NULL, frequency TEXT NOT NULL,
    time_of_day TEXT, day_of_week INTEGER, status TEXT NOT NULL DEFAULT 'active',
    interval_minutes INTEGER, notify_always INTEGER NOT NULL DEFAULT 0,
    has_unseen_result INTEGER NOT NULL DEFAULT 0, last_result_title TEXT,
    last_summary TEXT, last_run_status TEXT, last_run_notified INTEGER NOT NULL DEFAULT 0,
    trigger_type TEXT NOT NULL DEFAULT 'time', phrases TEXT, trigger_config TEXT, conditions TEXT, run_mode TEXT NOT NULL DEFAULT 'auto', run_requested_at TEXT, run_trigger TEXT,
    next_run_at TEXT NOT NULL, last_run_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
// next_run_at in the past, so it's claimable right now.
$db->exec("INSERT INTO scheduled_tasks (user_id, conversation_id, label, instruction, frequency, next_run_at)
    VALUES (1, 1, 'Test', 'Do a thing.', 'hourly', '2000-01-01 00:00:00')");

$tasks = new ScheduledTasks($db);
$farFuture = '2099-01-01 00:00:00'; // clearly ahead of UTC_TIMESTAMP(), i.e. not re-claimable
$first  = $tasks->claimAndReschedule(1, $farFuture);
$second = $tasks->claimAndReschedule(1, $farFuture);
check('first claim succeeds', $first === true);
check('second claim (now in the future) fails', $second === false);

$row = $db->query('SELECT next_run_at FROM scheduled_tasks WHERE id = 1')->fetch();
check('next_run_at was actually advanced', $row['next_run_at'] === $farFuture, (string) $row['next_run_at']);

echo "9) markResult() / unseenForUser() / clearUnseenByConversation() — the \"missed push\" banner data\n";
check('nothing unseen yet', $tasks->unseenForUser(1) === []);

$tasks->markResult(1, 'Found an invoice email');
$unseen = $tasks->unseenForUser(1);
check('now one unseen task', count($unseen) === 1, (string) count($unseen));
check('carries the result title', ($unseen[0]['last_result_title'] ?? null) === 'Found an invoice email', json_encode($unseen));
check('carries its conversation id (for the banner to open)', ($unseen[0]['conversation_id'] ?? null) === 1, json_encode($unseen));

check('a different user sees nothing (owner-scoped)', $tasks->unseenForUser(2) === []);

$tasks->clearUnseenByConversation(1, 1);
check('cleared after "opening" its conversation', $tasks->unseenForUser(1) === []);

echo "10) every N minutes: +N from \$after, clamped to the allowed range\n";
$after = new DateTimeImmutable('2026-10-13 12:00:00', $utc);
check('+15 minutes', ScheduledTasks::nextRunAtUtc('minutes', null, null, $after, 15)->getTimestamp() - $after->getTimestamp() === 900);
check('below the floor -> 5 minutes', ScheduledTasks::nextRunAtUtc('minutes', null, null, $after, 1)->getTimestamp() - $after->getTimestamp() === 300);
check('above the ceiling -> 24 hours', ScheduledTasks::nextRunAtUtc('minutes', null, null, $after, 99999)->getTimestamp() - $after->getTimestamp() === 86400);

echo "11) weekdays: Monday to Friday only, at the local time\n";
// 2026-10-16 is a Friday. After 08:00 on Friday the next run is MONDAY the 19th.
$after = new DateTimeImmutable('2026-10-16 12:00:00', $utc);
$next  = ScheduledTasks::nextRunAtUtc('weekdays', '07:00', null, $after);
check('Friday afternoon -> Monday', $next->setTimezone($tz)->format('Y-m-d') === '2026-10-19', $next->format('c'));
check('at 07:00 local', $localTime($next) === '07:00', $localTime($next));
$after = new DateTimeImmutable('2026-10-14 12:00:00', $utc); // Wednesday
check('Wednesday afternoon -> Thursday', ScheduledTasks::nextRunAtUtc('weekdays', '07:00', null, $after)->setTimezone($tz)->format('Y-m-d') === '2026-10-15');
$after = new DateTimeImmutable('2026-10-17 06:00:00', $utc); // Saturday morning
check('Saturday -> Monday', ScheduledTasks::nextRunAtUtc('weekdays', '07:00', null, $after)->setTimezone($tz)->format('Y-m-d') === '2026-10-19');

echo "12) create() stores the new fields; a one-off runs once and is then done\n";
$id = $tasks->create(1, 1, 'Lights', 'Turn on the lights if I am home.', 'once', null, null, null, true, new DateTimeImmutable('2000-01-02 00:00:00', $utc));
$row = $db->query("SELECT frequency, notify_always, next_run_at FROM scheduled_tasks WHERE id = $id")->fetch();
check('one-off keeps its own run time', $row['next_run_at'] === '2000-01-02 00:00:00' && $row['frequency'] === 'once', json_encode($row));
check('notify_always is saved', (int) $row['notify_always'] === 1);
$every = $tasks->create(1, 1, 'Poll', 'Check.', 'minutes', null, null, 10);
$poll  = $db->query("SELECT interval_minutes FROM scheduled_tasks WHERE id = $every")->fetch();
check('the interval is saved', (int) $poll['interval_minutes'] === 10);
check('first claim of the one-off wins', $tasks->claimOnce($id) === true);
check('second claim of the one-off loses', $tasks->claimOnce($id) === false);
check('a finished one-off is no longer active', !in_array($id, array_column($tasks->listForUser(1), 'id'), true));
$listed = array_column($tasks->listForUser(1), null, 'id');
check('list carries interval and notify flag', ($listed[$every]['interval_minutes'] ?? null) === 10 && ($listed[$every]['notify_always'] ?? null) === false);

echo "13) opening a run from its notification: boilerplate stripped, task found by conversation\n";
$old = "[Scheduled task — this is an unattended run, nobody is watching live. Do ONLY what the instruction below "
    . "specifically asks (e.g. which lights you turned on). If the home agent was unreachable (offline / no answer), do not notify.]\n\n"
    . "Get the weather forecast and send a notification.";
check('an old stored run loses its rules', ScheduledTasks::stripRunPreamble($old) === 'Get the weather forecast and send a notification.', ScheduledTasks::stripRunPreamble($old));
check('a retry preamble too', ScheduledTasks::stripRunPreamble("[Scheduled task — RETRY. Try once more.]\n\nTurn on the lights.") === 'Turn on the lights.');
check('a plain instruction is untouched', ScheduledTasks::stripRunPreamble('Check my email for invoices.') === 'Check my email for invoices.');
check('only a leading preamble is removed', ScheduledTasks::stripRunPreamble('Say "[Scheduled task]" back to me') === 'Say "[Scheduled task]" back to me');
$task = $tasks->byConversation(1, 1);
check('the task that owns a conversation is found', ($task['label'] ?? '') === 'Test' && ($task['id'] ?? 0) === 1, json_encode($task));
check('an ordinary chat is not a task', $tasks->byConversation(1, 999) === null);
check('another user cannot see it', $tasks->byConversation(2, 1) === null);

echo "14) the card: listing, pause / resume, last run\n";
$db2 = new PDO('sqlite::memory:');
$db2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db2->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db2->exec("CREATE TABLE scheduled_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, conversation_id INTEGER NOT NULL,
    label TEXT NOT NULL, instruction TEXT NOT NULL, frequency TEXT NOT NULL,
    time_of_day TEXT, day_of_week INTEGER, status TEXT NOT NULL DEFAULT 'active',
    interval_minutes INTEGER, notify_always INTEGER NOT NULL DEFAULT 0,
    has_unseen_result INTEGER NOT NULL DEFAULT 0, last_result_title TEXT,
    last_summary TEXT, last_run_status TEXT, last_run_notified INTEGER NOT NULL DEFAULT 0,
    trigger_type TEXT NOT NULL DEFAULT 'time', phrases TEXT, trigger_config TEXT, conditions TEXT, run_mode TEXT NOT NULL DEFAULT 'auto', run_requested_at TEXT, run_trigger TEXT,
    next_run_at TEXT NOT NULL, last_run_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$t2 = new ScheduledTasks($db2);
$a = $t2->create(1, 10, 'Invoices', 'Check my mail for invoices.', 'weekdays', '08:00', null);
$b = $t2->create(1, 11, 'Lights', 'Turn the lights on if I am home.', 'minutes', null, null, 30, true);
$c = $t2->create(1, 12, 'Old one-off', 'x', 'daily', '09:00', null);
$d = $t2->create(2, 13, 'Someone else', 'y', 'daily', '09:00', null);
$db2->exec("UPDATE scheduled_tasks SET status = 'done', frequency = 'once', last_run_at = '2026-10-01 07:00:00' WHERE id = $c");
$list = $t2->listForCard(1);
check('only this user, active first (soonest), finished one-off last', array_column($list, 'id') === [$b, $a, $c] || array_column($list, 'id') === [$a, $b, $c], json_encode(array_column($list, 'id')));
check('another user\'s task is not listed or found', $t2->find(1, $d) === null && count($list) === 3);

echo "   pause / resume\n";
check('pausing works', $t2->setActive(1, $a, false) === null && $t2->find(1, $a)['status'] === 'paused');
check('a paused task is not due', array_column(array_filter($t2->listForCard(1), fn ($t) => $t['status'] === 'active'), 'id') === [$b]);
$db2->exec("UPDATE scheduled_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE id = $a");
check('a paused task never shows as due, even long overdue', !in_array($a, array_column($t2->due(), 'id'), true));
check('resuming works and schedules from NOW (the missed slots are not replayed)', $t2->setActive(1, $a, true) === null
    && $t2->find(1, $a)['status'] === 'active' && $t2->find(1, $a)['next_run_at'] > gmdate('Y-m-d H:i:s'));
check('resuming one that is active is fine', $t2->setActive(1, $a, true) === null);
check('someone else cannot pause it', $t2->setActive(2, $a, false) === 'No such scheduled task.' && $t2->find(1, $a)['status'] === 'active');
check('a finished one-off cannot be resumed', is_string($t2->setActive(1, $c, true)));
$once = $t2->create(1, 14, 'Once', 'z', 'once', null, null, null, false, new DateTimeImmutable('+2 days', new DateTimeZone('UTC')));
$t2->setActive(1, $once, false);
check('a paused one-off resumes while its time is ahead, keeping that time', $t2->setActive(1, $once, true) === null && $t2->find(1, $once)['status'] === 'active');
$db2->exec("UPDATE scheduled_tasks SET status = 'paused', next_run_at = '2000-01-01 00:00:00' WHERE id = $once");
check('…but not once it has passed', is_string($t2->setActive(1, $once, true)) && $t2->find(1, $once)['status'] === 'paused');

echo "   last run\n";
$db2->exec("UPDATE scheduled_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE id = $b");
$t2->claimAndReschedule($b, '2099-01-01 00:00:00');
$t2->markResult($b, 'Lights on');
check('a notify_me marks the run as notified, with its title', $t2->find(1, $b)['last_run_notified'] === true && $t2->find(1, $b)['last_result_title'] === 'Lights on');
$t2->recordRun($b, true, "Turned on the living room.\n");
check('the run\'s closing words are kept', $t2->find(1, $b)['last_summary'] === 'Turned on the living room.' && $t2->find(1, $b)['last_run_status'] === 'ok');
$db2->exec("UPDATE scheduled_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE id = $b");
$t2->claimAndReschedule($b, '2099-01-01 00:00:00');
check('the next run starts un-notified (a quiet run does not inherit the last one)', $t2->find(1, $b)['last_run_notified'] === false && $t2->find(1, $b)['last_result_title'] === 'Lights on');
$t2->recordRun($b, false, str_repeat('x', 3000));
check('an error is recorded and long text capped', $t2->find(1, $b)['last_run_status'] === 'error' && mb_strlen($t2->find(1, $b)['last_summary']) === 1500);

echo "   the card\n";
$card = ScheduledTasks::card($t2->listForCard(1));
check('kind, no system section for a non-developer', $card['kind'] === 'scheduled_tasks' && !isset($card['system']));
$bi = array_values(array_filter($card['items'], fn ($i) => $i['id'] === $b))[0];
check('schedule text, tell-me-every-time, last run', $bi['schedule'] === 'Every 30 minutes' && $bi['tell_always'] === true && $bi['last_status'] === 'error' && $bi['next_run'] !== null);
$ci = array_values(array_filter($card['items'], fn ($i) => $i['id'] === $c))[0];
check('a finished one-off has no next run', $ci['next_run'] === null && $ci['once'] === true && $ci['status'] === 'done');
check('with a system section for the developer', ScheduledTasks::card([], [['key' => 'x']])['system'] === [['key' => 'x']]);
check('schedule wording', ScheduledTasks::describeSchedule('weekdays', '08:00', null, null) === 'Every weekday at 08:00'
    && ScheduledTasks::describeSchedule('weekly', '19:30', 7, null) === 'Every Sunday at 19:30'
    && ScheduledTasks::describeSchedule('minutes', null, null, 120) === 'Every 2 hours'
    && ScheduledTasks::describeSchedule('minutes', null, null, 60) === 'Every hour'
    && ScheduledTasks::describeSchedule('daily', '4:05', null, null) === 'Every day at 04:05');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
