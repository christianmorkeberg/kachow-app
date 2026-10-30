<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lets a web request answer the browser EARLY and keep running (a slow chat turn: the browser gets "still working", the turn finishes in the
 * background and is picked up by polling or a push). Only available where PHP can close the connection while the script goes on
 * (FastCGI / LiteSpeed); elsewhere available() is false and a turn simply stays attached, as before.
 *
 * Also the "someone is watching" marker: the page polls for the late answer, and each poll touches a file; the background run skips the push when
 * a poll came in the last seconds — nobody needs a banner for something on the screen they are looking at.
 */
final class Detach
{
    /** A poll newer than this means the chat is open and watching. */
    public const WATCH_SECONDS = 20;

    public static function available(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /** Sends the JSON answer, closes the connection and lets the script go on for up to $keepRunningS more seconds. @param array<string, mixed> $body */
    public static function release(int $status, array $body, int $keepRunningS = 180): void
    {
        ignore_user_abort(true);
        @set_time_limit($keepRunningS);
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        http_response_code($status);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        echo $json;
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    public static function touchWatch(int $userId, int $conversationId): void
    {
        @touch(self::watchFile($userId, $conversationId));
    }

    public static function watched(int $userId, int $conversationId, ?int $now = null): bool
    {
        $m = @filemtime(self::watchFile($userId, $conversationId));

        return $m !== false && ($now ?? time()) - $m <= self::WATCH_SECONDS;
    }

    private static function watchFile(int $userId, int $conversationId): string
    {
        return sys_get_temp_dir() . '/kachow-watch-' . $userId . '-' . $conversationId;
    }
}
