<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Starts a bin/ script as its own PHP process for the dispatcher (so a job's `exit` and its globals stay
 * its own) and collects what it printed. KACHOW_DISPATCHED=1 tells the script's guard this is the dispatcher
 * starting it, not the cPanel entry.
 */
final class SystemTaskRunner
{
    /** Whether this PHP may start processes at all (shared hosts often disable it). */
    public static function canLaunch(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('proc_open', $disabled, true);
    }

    /**
     * @param list<string> $command argv, e.g. [PHP_BINARY, '/path/bin/x.php']
     * @return array{ok:bool, exit:int, output:string, ms:int, error:?string}
     */
    public static function run(array $command, int $timeoutSeconds = 600): array
    {
        $t0 = microtime(true);
        if (!self::canLaunch()) {
            return ['ok' => false, 'exit' => -1, 'output' => '', 'ms' => 0, 'error' => 'proc_open is disabled'];
        }
        $env  = array_merge(getenv() ?: [], ['KACHOW_DISPATCHED' => '1']);
        $proc = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'exit' => -1, 'output' => '', 'ms' => 0, 'error' => 'could not start the process'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $timedOut = false;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) - $t0 > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($proc);
                break;
            }
            usleep(100_000);
        }
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        // proc_close reports -1 when proc_get_status already saw the exit: prefer that value.
        $code = isset($status) && $status['running'] === false && $status['exitcode'] >= 0 ? $status['exitcode'] : $exit;
        if ($timedOut) {
            $out .= "\nStopped after {$timeoutSeconds} s.";
        }

        return [
            'ok'     => !$timedOut && $code === 0,
            'exit'   => $code,
            'output' => mb_substr(trim($out), -4000),
            'ms'     => (int) round((microtime(true) - $t0) * 1000),
            'error'  => $timedOut ? 'timed out' : null,
        ];
    }
}
