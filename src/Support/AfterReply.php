<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Work that should go on AFTER the answer has reached the browser — "wait for the valve to confirm, then push", later anything else that waits for the world.
 * A tool that wants a follow-up calls collect() during the turn; api/chat.php then releases the connection (Detach::release) and calls run(). Items of the same
 * key are handed to ONE runner together (three radiators = one watcher, one push). Only exists where the connection can really be released early:
 * chat.php calls enable() just then, and a tool checks enabled() BEFORE promising the user a follow-up. Static on purpose: one web request = one turn.
 */
final class AfterReply
{
    private static bool $enabled = false;

    /** @var array<string, mixed> */
    private static array $context = [];

    /** @var array<string, array{items: list<array<string, mixed>>, runner: callable, maxSeconds: int}> */
    private static array $jobs = [];

    /** @param array<string, mixed> $context what the runners may need: conversation_id, danish (the user's language) */
    public static function enable(array $context = []): void
    {
        self::$enabled = true;
        self::$context = $context;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }

    /** @return array<string, mixed> */
    public static function context(): array
    {
        return self::$context;
    }

    /**
     * Queues one item for the follow-up $key. The first call for a key fixes its runner; later calls only add items.
     *
     * @param array<string, mixed>                                $item
     * @param callable(list<array<string, mixed>>, array<string, mixed>): void $runner called once at run() with all items of the key and the context
     */
    public static function collect(string $key, array $item, callable $runner, int $maxSeconds = 300): void
    {
        if (!self::$enabled) {
            return;
        }
        self::$jobs[$key]['items'][]   = $item;
        self::$jobs[$key]['runner']  ??= $runner;
        self::$jobs[$key]['maxSeconds'] = max(self::$jobs[$key]['maxSeconds'] ?? 0, $maxSeconds);
    }

    public static function hasWork(): bool
    {
        return self::$jobs !== [];
    }

    /** How long the request must be allowed to go on: the longest job plus a minute of slack. */
    public static function maxSeconds(): int
    {
        $m = 0;
        foreach (self::$jobs as $j) {
            $m = max($m, $j['maxSeconds']);
        }

        return $m + 60;
    }

    /** Runs every queued job once, each isolated (one failing never stops the next), then clears the queue. */
    public static function run(): void
    {
        $jobs       = self::$jobs;
        self::$jobs = [];
        foreach ($jobs as $key => $job) {
            try {
                ($job['runner'])($job['items'], self::$context);
            } catch (\Throwable $e) {
                error_log('AfterReply ' . $key . ': ' . $e->getMessage());
            }
        }
    }

    /** For tests: back to a clean state. */
    public static function reset(): void
    {
        self::$enabled = false;
        self::$context = [];
        self::$jobs    = [];
    }
}
