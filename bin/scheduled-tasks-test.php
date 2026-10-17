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

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
