<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\SystemTasks;
use Throwable;

/**
 * Called at the top of each cron script in bin/ that is a catalogued system task. It makes the script
 * obey the task's state — exits quietly when the job is paused, or when the dispatcher owns it and this is
 * the cPanel entry firing — and records what the run came to (when, ok/error, how long, what it printed).
 *
 * It must never make a job worse: if the table is missing (migration not run) or the database is down,
 * the job simply runs as it always did.
 */
final class SystemTaskGuard
{
    private static ?string $key = null;
    private static float $startedAt = 0.0;
    private static string $output = '';

    public static function begin(string $key): void
    {
        if (self::$key !== null) {
            return;
        }
        // A person running the script by hand with options (--dry-run, --redo …) is never held back or recorded.
        foreach (array_slice((array) ($_SERVER['argv'] ?? []), 1) as $arg) {
            if (str_starts_with((string) $arg, '--')) {
                return;
            }
        }
        $dispatched = getenv('KACHOW_DISPATCHED') === '1';
        try {
            $tasks = new SystemTasks();
            $gate  = $tasks->gate($key, $dispatched);
            if ($gate === 'paused') {
                exit(0);   // paused: not run, and the last real run's record is left alone
            }
            if ($gate === 'elsewhere') {
                exit(0);   // the dispatcher owns this job; the cPanel entry is a no-op
            }
        } catch (Throwable $e) {
            return;   // no table / no database: run as before, unrecorded
        }

        self::$key       = $key;
        self::$startedAt = microtime(true);
        // Tee: keep a copy of what the script prints (for the card) and let it through unchanged.
        ob_start(static function (string $chunk): string {
            self::$output = mb_substr(self::$output . $chunk, -4000);

            return $chunk;
        }, 1024);
        register_shutdown_function([self::class, 'finish']);
    }

    /** Registered shutdown function — public only so PHP can call it. */
    public static function finish(): void
    {
        if (self::$key === null) {
            return;
        }
        $key = self::$key;
        self::$key = null;
        $err = error_get_last();
        $fatal = $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        // What the script printed since the buffer last handed a chunk on (PHP flushes it after this).
        $out = self::$output . (ob_get_level() > 0 ? (string) ob_get_contents() : '');
        if ($fatal) {
            $out .= ($out === '' ? '' : "\n") . 'Fatal: ' . $err['message'];
        }
        try {
            (new SystemTasks())->recordRun($key, $fatal ? 'error' : 'ok', (int) round((microtime(true) - self::$startedAt) * 1000), $out);
        } catch (Throwable $e) {
            // never let the bookkeeping break a job
        }
    }
}
