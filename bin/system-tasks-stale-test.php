<?php

declare(strict_types=1);

/**
 * "A background job has stopped": SystemTasks::staleAfterMinutes / overdueMinutes / ago / cardRows (pure, no database):
 *   php bin/system-tasks-stale-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\SystemTasks;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$now = new DateTimeImmutable('2026-11-02 16:00:00', new DateTimeZone('UTC'));
$task = static fn (string $freq, ?int $every, ?string $last, string $status = 'active'): array => [
    'key' => 'x', 'label' => 'X', 'description' => '', 'script' => 'x.php', 'status' => $status, 'mode' => 'cpanel', 'frequency' => $freq,
    'time_of_day' => $freq === 'minutes' ? null : '04:00', 'day_of_week' => $freq === 'weekly' ? 7 : null, 'interval_minutes' => $every,
    'next_run_at' => null, 'last_run_at' => $last, 'last_status' => 'ok', 'last_duration_ms' => 5, 'last_output' => null,
];
$ago = static fn (string $mod): string => $now->modify($mod)->format('Y-m-d H:i:s');

echo "1) thresholds\n";
check('5-minute job: 15 minutes', SystemTasks::staleAfterMinutes('minutes', 5) === 15);
check('30-minute job: twice the interval', SystemTasks::staleAfterMinutes('minutes', 30) === 60);
check('1-minute job: not less than 15', SystemTasks::staleAfterMinutes('minutes', 1) === 15);
check('daily 30 h, weekdays 80 h, weekly 8 days', SystemTasks::staleAfterMinutes('daily', null) === 1800 && SystemTasks::staleAfterMinutes('weekdays', null) === 4800 && SystemTasks::staleAfterMinutes('weekly', null) === 11520);

echo "\n2) who counts as stopped\n";
check('ran a minute ago: fine', SystemTasks::overdueMinutes($task('minutes', 5, $ago('-1 minute')), $now) === null);
check('ran 14 minutes ago (5-min job): fine', SystemTasks::overdueMinutes($task('minutes', 5, $ago('-14 minutes')), $now) === null);
check('ran 3 days ago (5-min job): stopped, 4320 min', SystemTasks::overdueMinutes($task('minutes', 5, $ago('-3 days')), $now) === 4320);
check('a daily job that ran yesterday: fine', SystemTasks::overdueMinutes($task('daily', null, $ago('-20 hours')), $now) === null);
check('a daily job silent for 2 days: stopped', SystemTasks::overdueMinutes($task('daily', null, $ago('-2 days')), $now) === 2880);
check('a weekly job last Sunday: fine', SystemTasks::overdueMinutes($task('weekly', null, $ago('-7 days')), $now) === null);
check('paused jobs are never judged', SystemTasks::overdueMinutes($task('minutes', 5, $ago('-3 days'), 'paused'), $now) === null);
check('a job that never reported is not judged', SystemTasks::overdueMinutes($task('minutes', 5, null), $now) === null);

echo "\n3) wording and the card\n";
check('ago()', SystemTasks::ago(20) === '20 min' && SystemTasks::ago(300) === '5 h' && SystemTasks::ago(4320) === '3 days');
// cardRows judges against the real clock, so these two are relative to it (not to the fixed $now above).
$real = static fn (string $mod): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($mod)->format('Y-m-d H:i:s');
$rows = SystemTasks::cardRows([$task('minutes', 5, $real('-3 days')), $task('minutes', 5, $real('-1 minute'))]);
check('the card row carries the overdue text for a stopped job only', $rows[0]['overdue'] === '3 days' && $rows[1]['overdue'] === null);

echo "\n" . ($fail === 0 ? "OK — {$pass} checks passed.\n" : "FAILED — {$fail} of " . ($pass + $fail) . ".\n");
exit($fail === 0 ? 0 : 1);
