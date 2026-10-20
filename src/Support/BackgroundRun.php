<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Starts a bin/ script in the background, detached, so a request (a spoken "I'm leaving", a location update) is not held up by it.
 * Best effort: hosts often disable exec; then nothing happens and the per-minute cron picks the work up a minute later anyway.
 */
final class BackgroundRun
{
    public static function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }

    /** @param list<string> $args */
    public static function kick(string $script, array $args = []): bool
    {
        if (!self::canExec() || !is_file($script)) {
            return false;
        }
        $php = PHP_BINARY !== '' && is_executable(PHP_BINARY) && !str_contains(PHP_BINARY, 'fpm') && !str_contains(PHP_BINARY, 'cgi') ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' > /dev/null 2>&1 &';
        @exec($cmd);

        return true;
    }
}
