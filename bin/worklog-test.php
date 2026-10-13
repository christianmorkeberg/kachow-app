<?php

declare(strict_types=1);

/**
 * WorkLog integration self-test. Runs the REAL Data\WorkLog + Tools\LogWorkTime against an
 * in-memory SQLite DB (injected via the optional ?PDO constructor), so it exercises the
 * merge-not-duplicate fix from report #32 end to end.
 *
 *   php -d extension=php_pdo_sqlite bin/worklog-test.php
 *
 * Bug: a same-day follow-up answer (the assistant's own "how did X go?" nudge) had nowhere
 * to land — log_work_time always INSERTed, so calling it again would have duplicated the
 * day's entry, and the followup_hint never told the model to call it again at all. Fixed by
 * merging a same user/date/job add() into the existing row, and updating the hint to say so.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Auth\GoogleOAuth;
use App\Data\Calendar;
use App\Data\UserSettings;
use App\Data\Users;
use App\Data\WorkLog;
use App\Tools\LogWorkTime;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/worklog-test.php\n");
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
$db->exec("CREATE TABLE work_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    log_date TEXT NOT NULL, job TEXT NOT NULL, hours NUMERIC, description TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE user_settings (
    user_id INTEGER NOT NULL, setting_key TEXT NOT NULL, setting_value TEXT NOT NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, setting_key))");

$log = new WorkLog($db);
$U = 1;

echo "1) a first entry for a job/date inserts a new row\n";
$id1 = $log->add($U, '2026-10-12', 'DTU', 'Meeting about the journal.');
$rows = $log->listForUser($U, '2026-10-12', '2026-10-12');
check('one row', count($rows) === 1, (string) count($rows));
check('description is the original text', $rows[0]['description'] === 'Meeting about the journal.', $rows[0]['description']);

echo "2) a second call for the SAME job/date merges into it, not a duplicate\n";
$id2 = $log->add($U, '2026-10-12', 'DTU', 'The license plate lookup client was straightforward, no API quirks.');
$rows = $log->listForUser($U, '2026-10-12', '2026-10-12');
check('still one row', count($rows) === 1, (string) count($rows));
check('same id reused', $id2 === $id1, "$id2 vs $id1");
check('merged description keeps both parts',
    $rows[0]['description'] === 'Meeting about the journal. — The license plate lookup client was straightforward, no API quirks.',
    $rows[0]['description']);

echo "3) a different job on the same date does NOT merge\n";
$log->add($U, '2026-10-12', 'Client Y', 'Fixed an invoice.');
$rows = $log->listForUser($U, '2026-10-12', '2026-10-12');
check('two rows now', count($rows) === 2, (string) count($rows));

echo "4) a different date for the same job does NOT merge\n";
$log->add($U, '2026-10-13', 'DTU', 'Submitted the article for peer review.');
$rows = $log->listForUser($U, '2026-10-12', '2026-10-13', 'DTU');
check('two DTU rows across two dates', count($rows) === 2, (string) count($rows));

echo "5) job matching is case-insensitive (DTU vs dtu still merges)\n";
$before = count($log->listForUser($U, '2026-10-13', '2026-10-13'));
$log->add($U, '2026-10-13', 'dtu', 'Also set up LaTeX.');
$after = count($log->listForUser($U, '2026-10-13', '2026-10-13'));
check('no new row added', $after === $before, "$after vs $before");

echo "6) LogWorkTime tool: the followup_hint tells the model to call back in, not just chat\n";
// job is always given below, so jobsForDay() (the only path that touches Calendar/UserSettings)
// is never exercised — these just need to satisfy the constructor's types.
$calendar = new Calendar(new GoogleOAuth(new Users($db), 'x', 'x', 'x'));
$settings = new UserSettings($db);
$tool = new LogWorkTime($log, $calendar, $settings);
$result = $tool->execute(['description' => 'More detail from the follow-up.', 'job' => 'DTU', 'date' => '2026-10-12'], $U);
check('logged', $result['logged'] ?? false, json_encode($result));
check('hint tells the model to call log_work_time again on real detail',
    str_contains((string) ($result['followup_hint'] ?? ''), 'call log_work_time again'),
    (string) ($result['followup_hint'] ?? ''));
check('merged into the existing DTU/2026-10-12 entry, still just one row',
    count($log->listForUser($U, '2026-10-12', '2026-10-12', 'DTU')) === 1,
    (string) count($log->listForUser($U, '2026-10-12', '2026-10-12', 'DTU')));

echo "7) WorkLog::expandJobAlias() — report #35 (short job label -> full name)\n";
$aliases = "DSB=Danske Statsbaner\nDTU=Technical University of Denmark";
check('matches case-insensitively', WorkLog::expandJobAlias('dsb', $aliases) === 'Danske Statsbaner', WorkLog::expandJobAlias('dsb', $aliases));
check('a different known alias also expands', WorkLog::expandJobAlias('DTU', $aliases) === 'Technical University of Denmark', WorkLog::expandJobAlias('DTU', $aliases));
check('an unknown job passes through unchanged', WorkLog::expandJobAlias('Client Y', $aliases) === 'Client Y', WorkLog::expandJobAlias('Client Y', $aliases));
check('blank aliases setting is a no-op', WorkLog::expandJobAlias('DSB', '') === 'DSB', WorkLog::expandJobAlias('DSB', ''));
check('a malformed line (no "=") is ignored, not a crash', WorkLog::expandJobAlias('DSB', "not a mapping\nDSB=Danske Statsbaner") === 'Danske Statsbaner', WorkLog::expandJobAlias('DSB', "not a mapping\nDSB=Danske Statsbaner"));

echo "8) LogWorkTime applies the user's own job_aliases setting end to end\n";
// UserSettings::set() uses MySQL's ON DUPLICATE KEY UPDATE (not SQLite) — insert directly.
$db->prepare('INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (:u, :k, :v)')
    ->execute([':u' => $U, ':k' => 'job_aliases', ':v' => 'DSB=Danske Statsbaner']);
$result = $tool->execute(['description' => 'Standup and code review.', 'job' => 'DSB', 'date' => '2026-10-14'], $U);
check('tool result reports the EXPANDED name', ($result['job'] ?? null) === 'Danske Statsbaner', json_encode($result));
$rows = $log->listForUser($U, '2026-10-14', '2026-10-14');
check('stored job is the expanded name too', count($rows) === 1 && $rows[0]['job'] === 'Danske Statsbaner', json_encode($rows));

echo "9) without the setting, the short job label is stored as given (no surprise expansion)\n";
$settings->remove($U, 'job_aliases');
$tool->execute(['description' => 'Another day.', 'job' => 'DSB', 'date' => '2026-10-15'], $U);
$rows = $log->listForUser($U, '2026-10-15', '2026-10-15');
check('job stays "DSB" with no alias configured', count($rows) === 1 && $rows[0]['job'] === 'DSB', json_encode($rows));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
