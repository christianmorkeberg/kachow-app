<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\SystemTasks;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Starts the system tasks that are due and owned by the dispatcher (the per-minute scheduled-tasks cron).
 * Each is claimed and moved to its next slot first, exactly like a user's scheduled task, so a slow run can't
 * double-fire and a cron outage skips the missed slots instead of replaying them.
 */
final class SystemTaskDispatcher
{
    /**
     * @param callable(list<string>, int): array{ok:bool, exit:int, output:string, ms:int, error:?string} $launch
     * @return int how many jobs were started
     */
    public static function runDue(SystemTasks $tasks, callable $launch, string $binDir, ?DateTimeImmutable $now = null, int $timeoutSeconds = 900): int
    {
        $utc = new DateTimeZone('UTC');
        $now = $now ?? new DateTimeImmutable('now', $utc);
        $ran = 0;
        foreach ($tasks->due() as $t) {
            $next = SystemTasks::nextAfter($t, new DateTimeImmutable((string) $t['next_run_at'], $utc));
            if ($next <= $now) {
                $next = SystemTasks::nextAfter($t, $now);   // the cron was down for a while: skip the missed slots
            }
            if (!$tasks->claim($t['key'], $next->format('Y-m-d H:i:s'))) {
                continue;   // an overlapping run took it
            }
            $res = $launch([PHP_BINARY, rtrim($binDir, '/') . '/' . $t['script']], $timeoutSeconds);
            $ran++;
            if ($res['exit'] === -1 && $res['error'] !== null && $res['error'] !== 'timed out') {
                $tasks->fallBackToCpanel($t['key'], $res['error']);   // cannot start processes here: don't let the job silently stop
                continue;
            }
            $tasks->recordRun($t['key'], $res['ok'] ? 'ok' : 'error', $res['ms'], $res['output']);
        }

        return $ran;
    }
}
