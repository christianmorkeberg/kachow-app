<?php

declare(strict_types=1);

/**
 * System tasks: Data\SystemTasks (catalogue rows, pause/resume, schedule and trigger changes, the gate
 * a script asks, the dispatcher's claim) and the process runner. In-memory SQLite:
 *   php -d extension=php_pdo_sqlite bin/system-tasks-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ScheduledTasks;
use App\Data\SystemTasks;
use App\Data\Users;
use App\Tools\ListScheduledTasks;
use App\Tools\SetScheduledTaskActive;
use App\Tools\UpdateSystemTask;
use App\Support\SystemTaskDispatcher;
use App\Support\SystemTaskRunner;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/system-tasks-test.php\n");
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
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db->exec("CREATE TABLE system_tasks (task_key TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT 'active', mode TEXT NOT NULL DEFAULT 'cpanel',
    frequency TEXT NOT NULL, time_of_day TEXT, day_of_week INTEGER, interval_minutes INTEGER, next_run_at TEXT, last_run_at TEXT,
    last_status TEXT, last_duration_ms INTEGER, last_output TEXT)");
$st = new SystemTasks($db);

echo "1) the catalogue\n";
$all = $st->listAll();
check('one row per catalogued job, in catalogue order', array_column($all, 'key') === array_keys(SystemTasks::CATALOG), json_encode(array_column($all, 'key')));
check('they start active, on the cPanel entry, with today\'s schedule', $all[0]['status'] === 'active' && $all[0]['mode'] === 'cpanel'
    && $all[0]['frequency'] === 'minutes' && $all[0]['interval_minutes'] === 30);
check('rows are not duplicated by a second call', count($st->listAll()) === count(SystemTasks::CATALOG) && (int) $db->query('SELECT COUNT(*) FROM system_tasks')->fetchColumn() === count(SystemTasks::CATALOG));
$qa = $st->find('quick_actions');
check('a daily one keeps its time', $qa['frequency'] === 'daily' && $qa['time_of_day'] === '04:30');
check('every catalogued script exists in bin/', array_reduce(SystemTasks::CATALOG, fn ($ok, $c) => $ok && is_file(__DIR__ . '/' . $c['script']), true));
check('a name is resolved to its key', SystemTasks::resolveKey('Location day freeze') === 'location_freeze' && SystemTasks::resolveKey('location freeze') === 'location_freeze'
    && SystemTasks::resolveKey('quick actions') === 'quick_actions' && SystemTasks::resolveKey('memory') === 'memory_cleanup' && SystemTasks::resolveKey('nonsense') === null);

echo "2) pause / resume and the gate a script asks\n";
check('a running job is let through', $st->gate('reminders', false) === 'run');
check('pausing', $st->setActive('reminders', false) === true && $st->find('reminders')['status'] === 'paused');
check('a paused job is held back', $st->gate('reminders', false) === 'paused' && $st->gate('reminders', true) === 'paused');
check('resuming', $st->setActive('reminders', true) === true && $st->gate('reminders', false) === 'run');
check('an unknown job is refused; an uncatalogued script is let through', $st->setActive('nope', false) === false && $st->gate('something_else', false) === 'run');

echo "3) schedule and trigger\n";
check('a bad frequency / time / day / interval is refused', is_string($st->update('quick_actions', 'yearly')) && is_string($st->update('quick_actions', null, '25:00'))
    && is_string($st->update('memory_cleanup', null, null, 9)) && is_string($st->update('reminders', null, null, null, 1)) && is_string($st->update('quick_actions', null, null, null, null, 'cron')));
check('a minutes schedule needs an interval; a weekly one a day', is_string($st->update('quick_actions', 'minutes')) && is_string($st->update('quick_actions', 'weekly')));
check('the schedule changes', $st->update('quick_actions', null, '05:00') === null && $st->find('quick_actions')['time_of_day'] === '05:00');
check('daily → weekly with a day', $st->update('quick_actions', 'weekly', '06:15', 3) === null
    && $st->find('quick_actions')['frequency'] === 'weekly' && $st->find('quick_actions')['day_of_week'] === 3 && $st->find('quick_actions')['time_of_day'] === '06:15');
check('→ every N minutes drops the time of day', $st->update('quick_actions', 'minutes', null, null, 45) === null
    && $st->find('quick_actions')['interval_minutes'] === 45 && $st->find('quick_actions')['time_of_day'] === null);
check('on the cPanel entry there is no next run', $st->find('quick_actions')['next_run_at'] === null);
$st->update('quick_actions', 'daily', '04:30');

echo "4) the dispatcher owns a job\n";
check('switching to the dispatcher schedules the next run', $st->update('quick_actions', null, null, null, null, 'dispatcher') === null
    && $st->find('quick_actions')['mode'] === 'dispatcher' && $st->find('quick_actions')['next_run_at'] > gmdate('Y-m-d H:i:s'));
check('the cPanel entry then stands aside; the dispatcher\'s own start runs', $st->gate('quick_actions', false) === 'elsewhere' && $st->gate('quick_actions', true) === 'run');
check('nothing is due yet', $st->due() === []);
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key = 'quick_actions'");
check('overdue → due', array_column($st->due(), 'key') === ['quick_actions']);
$next = SystemTasks::nextAfter($st->find('quick_actions'));
check('the next slot is the daily time, in the future', $next > new DateTimeImmutable('now', new DateTimeZone('UTC'))
    && $next->setTimezone(new DateTimeZone('Europe/Copenhagen'))->format('H:i') === '04:30');
check('the claim succeeds once …', $st->claim('quick_actions', $next->format('Y-m-d H:i:s')) === true);
check('… and not twice (an overlapping minute\'s run)', $st->claim('quick_actions', $next->format('Y-m-d H:i:s')) === false && $st->due() === []);
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key = 'quick_actions'");
$st->setActive('quick_actions', false);
check('a paused dispatcher job is not due', $st->due() === []);
$st->setActive('quick_actions', true);
check('resuming schedules from now, not the missed slot', $st->find('quick_actions')['next_run_at'] > gmdate('Y-m-d H:i:s') && $st->due() === []);
$st->fallBackToCpanel('quick_actions', 'proc_open is disabled');
check('a job the dispatcher cannot start goes back to cPanel, with the reason recorded', $st->find('quick_actions')['mode'] === 'cpanel'
    && $st->find('quick_actions')['last_status'] === 'error' && str_contains($st->find('quick_actions')['last_output'], 'proc_open') && $st->gate('quick_actions', false) === 'run');
check('switching back to cPanel clears the next run', $st->update('home_sync', null, null, null, null, 'dispatcher') === null && $st->update('home_sync', null, null, null, null, 'cpanel') === null
    && $st->find('home_sync')['next_run_at'] === null);

echo "5) what a run came to\n";
$st->recordRun('reminders', 'ok', 1234, "sent 2\n");
$r = $st->find('reminders');
check('time, status, duration and output', $r['last_status'] === 'ok' && $r['last_duration_ms'] === 1234 && $r['last_output'] === 'sent 2' && $r['last_run_at'] !== null);
$st->recordRun('reminders', 'error', 5, str_repeat('x', 6000));
check('long output keeps its tail, capped', mb_strlen($st->find('reminders')['last_output']) === 4000 && str_starts_with($st->find('reminders')['last_output'], '…'));
$st->recordRun('reminders', 'bogus', 1, 'x');
check('a bogus status is ignored', $st->find('reminders')['last_status'] === 'error');
$rows = SystemTasks::cardRows($st->listAll());
$rem = array_values(array_filter($rows, fn ($x) => $x['key'] === 'reminders'))[0];
check('the card row: schedule text, last run, no next run on cPanel', $rem['schedule'] === 'Every 5 minutes' && $rem['last_status'] === 'error' && $rem['next_run'] === null && $rem['mode'] === 'cpanel');
$qa = array_values(array_filter($rows, fn ($x) => $x['key'] === 'quick_actions'))[0];
check('a daily one reads "Every day at 04:30"', $qa['schedule'] === 'Every day at 04:30');

echo "6) the process runner\n";
check('this PHP can start processes', SystemTaskRunner::canLaunch());
$ok = SystemTaskRunner::run([PHP_BINARY, '-r', 'echo getenv("KACHOW_DISPATCHED"), " out"; fwrite(STDERR, " err");']);
check('it runs a script, flags it as dispatched and gathers both streams', $ok['ok'] === true && $ok['exit'] === 0 && str_contains($ok['output'], '1 out') && str_contains($ok['output'], 'err'), json_encode($ok));
$bad = SystemTaskRunner::run([PHP_BINARY, '-r', 'echo "boom"; exit(3);']);
check('a failing exit code is an error, with its output', $bad['ok'] === false && $bad['exit'] === 3 && str_contains($bad['output'], 'boom'), json_encode($bad));
$slow = SystemTaskRunner::run([PHP_BINARY, '-r', 'sleep(5);'], 1);
check('a runaway is stopped at the time limit', $slow['ok'] === false && $slow['error'] === 'timed out' && $slow['ms'] < 4000, json_encode($slow));

echo "7) the dispatcher\n";
$db->exec("DELETE FROM system_tasks");
$st = new SystemTasks($db);
$st->ensureRows();
$st->update('reminders', null, null, null, 5, 'dispatcher');
$st->update('quick_actions', null, null, null, null, 'dispatcher');
$calls = [];
$fake = function (array $cmd, int $t) use (&$calls): array { $calls[] = basename($cmd[1]); return ['ok' => true, 'exit' => 0, 'output' => 'did it', 'ms' => 42, 'error' => null]; };
check('nothing due → nothing started', SystemTaskDispatcher::runDue($st, $fake, __DIR__) === 0 && $calls === []);
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key IN ('reminders', 'quick_actions')");
$n = SystemTaskDispatcher::runDue($st, $fake, __DIR__);
check('the two due jobs run, each started as its own script', $n === 2 && $calls === ['reminders-cron.php', 'quick-actions-cron.php'], json_encode($calls));
check('their result is recorded', $st->find('reminders')['last_status'] === 'ok' && $st->find('reminders')['last_output'] === 'did it' && $st->find('reminders')['last_duration_ms'] === 42);
check('a cron outage skips the missed slots: the next run is in the future, once', $st->find('reminders')['next_run_at'] > gmdate('Y-m-d H:i:s'));
check('running again right away starts nothing', SystemTaskDispatcher::runDue($st, $fake, __DIR__) === 0 && count($calls) === 2);
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key = 'reminders'");
$boom = fn (array $c, int $t): array => ['ok' => false, 'exit' => 2, 'output' => 'it broke', 'ms' => 7, 'error' => null];
SystemTaskDispatcher::runDue($st, $boom, __DIR__);
check('a failing script is recorded as an error, and the job stays on the dispatcher', $st->find('reminders')['last_status'] === 'error' && $st->find('reminders')['mode'] === 'dispatcher');
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key = 'reminders'");
$cant = fn (array $c, int $t): array => ['ok' => false, 'exit' => -1, 'output' => '', 'ms' => 0, 'error' => 'proc_open is disabled'];
SystemTaskDispatcher::runDue($st, $cant, __DIR__);
check('processes cannot be started here → the job goes back to cPanel instead of silently stopping', $st->find('reminders')['mode'] === 'cpanel'
    && str_contains($st->find('reminders')['last_output'], 'proc_open'));
$db->exec("UPDATE system_tasks SET next_run_at = '2000-01-01 00:00:00' WHERE task_key = 'quick_actions'");
$st->setActive('quick_actions', false);
check('a paused job is not started', SystemTaskDispatcher::runDue($st, $fake, __DIR__) === 0);

echo "8) the tools\n";
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT)");
$db->exec("INSERT INTO users (id, name, role) VALUES (1, 'Dev', 'admin'), (2, 'Alex', 'user')");
$db->exec("CREATE TABLE scheduled_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, conversation_id INTEGER NOT NULL,
    label TEXT NOT NULL, instruction TEXT NOT NULL, frequency TEXT NOT NULL,
    time_of_day TEXT, day_of_week INTEGER, status TEXT NOT NULL DEFAULT 'active',
    interval_minutes INTEGER, notify_always INTEGER NOT NULL DEFAULT 0,
    has_unseen_result INTEGER NOT NULL DEFAULT 0, last_result_title TEXT,
    last_summary TEXT, last_run_status TEXT, last_run_notified INTEGER NOT NULL DEFAULT 0,
    trigger_type TEXT NOT NULL DEFAULT 'time', phrases TEXT, trigger_config TEXT, conditions TEXT, run_mode TEXT NOT NULL DEFAULT 'auto', run_requested_at TEXT, run_trigger TEXT,
    next_run_at TEXT NOT NULL, last_run_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$sched = new ScheduledTasks($db);
$users = new Users($db);
$mine  = $sched->create(2, 5, 'Invoices', 'Check my mail.', 'daily', '08:00', null);
$sched->recordRun($mine, true, 'Nothing new.');
$list = new ListScheduledTasks($sched, $st, $users);
$r = $list->execute([], 2);
check('a user gets the card with their tasks and no system section', $r['_render']['kind'] === 'scheduled_tasks' && count($r['tasks']) === 1 && !isset($r['_render']['system']) && !isset($r['system_tasks']));
check('the model is told what the last run came to', $r['tasks'][0]['last_said'] === 'Nothing new.' && $r['tasks'][0]['status'] === 'active');
$r = $list->execute([], 1);
check('the developer also gets the system jobs', count($r['_render']['system']) === count(SystemTasks::CATALOG) && count($r['system_tasks']) === count(SystemTasks::CATALOG));
$set = new SetScheduledTaskActive($sched, $st, $users);
$r = $set->execute(['id' => $mine, 'active' => false], 2);
check('pausing your own task returns the card', $r['ok'] === true && $r['status'] === 'paused' && $r['_render']['items'][0]['status'] === 'paused');
check('resuming', $set->execute(['id' => $mine, 'active' => true], 2)['status'] === 'active');
check('"false" as text pauses too; missing active is refused', $set->execute(['id' => $mine, 'active' => 'false'], 2)['status'] === 'paused' && isset($set->execute(['id' => $mine], 2)['error']));
check('someone else\'s id is not found', ($set->execute(['id' => $mine, 'active' => true], 1)['ok'] ?? true) === false);
check('a system job needs the developer', isset($set->execute(['system_task' => 'reminders', 'active' => false], 2)['error']) && $st->find('reminders')['status'] === 'active');
$r = $set->execute(['system_task' => 'Location day freeze', 'active' => false], 1);
check('the developer pauses a system job by name', $r['ok'] === true && $st->find('location_freeze')['status'] === 'paused' && count($r['_render']['system']) === count(SystemTasks::CATALOG));
check('an unknown system job lists the real ones', str_contains($set->execute(['system_task' => 'bogus', 'active' => true], 1)['error'], 'Notifications'));
$set->execute(['system_task' => 'location freeze', 'active' => true], 1);
$upd = new UpdateSystemTask($st, $sched, $users);
check('only the developer changes a system job', isset($upd->execute(['system_task' => 'reminders', 'frequency' => 'daily'], 2)['error']));
$r = $upd->execute(['system_task' => 'location freeze', 'time_of_day' => '05:00'], 1);
check('moving a schedule says it is informational while cPanel starts it', $r['ok'] === true && $r['schedule'] === 'Every day at 05:00' && str_contains($r['note'], 'informational'), json_encode($r));
$r = $upd->execute(['system_task' => 'location freeze', 'trigger' => 'dispatcher'], 1);
check('switching to the dispatcher makes it real', $r['ok'] === true && $r['triggered_by'] === 'the per-minute dispatcher' && $st->find('location_freeze')['next_run_at'] !== null);
check('a bad value is explained; nothing to change is refused', ($upd->execute(['system_task' => 'location freeze', 'time_of_day' => 'noon'], 1)['ok'] ?? true) === false
    && isset($upd->execute(['system_task' => 'location freeze'], 1)['error']) && isset($upd->execute(['system_task' => 'zzz', 'trigger' => 'cpanel'], 1)['error']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
