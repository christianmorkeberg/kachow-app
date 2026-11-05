<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Database;
use PDO;
use Throwable;

/**
 * One row per tool call (see migrations/2026-11-05_tool_call_log.sql), written by ToolRegistry::dispatch() so EVERY entry point is
 * covered — chat, scheduled tasks, routines, voice, live, "Run" offers, exports. Tool name, outcome, duration and where it came
 * from; never arguments or results. A write that fails (table not migrated yet, DB hiccup) is swallowed: logging must never break a tool.
 */
final class ToolCallLog
{
    private ?PDO $db;
    private bool $broken = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    private function pdo(): PDO
    {
        return $this->db ??= Database::get();
    }

    public function record(int $userId, string $source, string $tool, bool $ok, int $ms, ?string $error, ?int $taskId = null, ?int $conversationId = null): void
    {
        if ($this->broken) {
            return;
        }
        try {
            $this->pdo()->prepare(
                'INSERT INTO tool_call_log (user_id, source, task_id, conversation_id, tool, ok, ms, error) VALUES (:u, :s, :t, :c, :tool, :ok, :ms, :e)'
            )->execute([
                ':u' => $userId, ':s' => mb_substr($source, 0, 16), ':t' => $taskId, ':c' => $conversationId, ':tool' => mb_substr($tool, 0, 80),
                ':ok' => $ok ? 1 : 0, ':ms' => max(0, $ms), ':e' => $error !== null ? mb_substr($error, 0, 200) : null,
            ]);
        } catch (Throwable $e) {
            $this->broken = true;   // not migrated yet / DB down: stop trying for the rest of this process
            error_log('tool_call_log: ' . $e->getMessage());
        }
    }

    /**
     * Calls per tool and source over the last $days days, busiest first.
     *
     * @return list<array{tool:string, source:string, calls:int, errors:int, avg_ms:int, last_at:string}>
     */
    public function summary(int $days = 30): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT tool, source, COUNT(*) AS calls, SUM(ok = 0) AS errors, AVG(ms) AS avg_ms, MAX(created_at) AS last_at
             FROM tool_call_log WHERE created_at >= :since GROUP BY tool, source ORDER BY calls DESC, tool ASC'
        );
        $stmt->execute([':since' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

        return array_map(static fn (array $r): array => [
            'tool' => (string) $r['tool'], 'source' => (string) $r['source'], 'calls' => (int) $r['calls'],
            'errors' => (int) $r['errors'], 'avg_ms' => (int) round((float) $r['avg_ms']), 'last_at' => (string) $r['last_at'],
        ], $stmt->fetchAll());
    }

    /**
     * The generic tools a scheduled task / routine has really used, learned from its own past runs — or null while there is too little
     * evidence (fewer than $minRuns runs that made a successful call). A "run" is the calls sharing a minute (a run takes seconds). A tool
     * counts if it worked at least once; one that only ever failed is left out, so a misfire is not learned.
     *
     * @return list<string>|null sorted tool names
     */
    public function learnedTools(int $taskId, int $minRuns = 3, int $days = 60): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT tool, ok, created_at FROM tool_call_log WHERE task_id = :t AND created_at >= :since ORDER BY id DESC LIMIT 200');
        $stmt->execute([':t' => $taskId, ':since' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        $runs = [];
        $tools = [];
        foreach ($stmt->fetchAll() as $r) {
            if ((int) $r['ok'] === 1) {
                $runs[substr((string) $r['created_at'], 0, 16)] = true;
                $tools[(string) $r['tool']] = true;
            }
        }
        if (count($runs) < $minRuns || $tools === []) {
            return null;
        }
        $names = array_keys($tools);
        sort($names);

        return $names;
    }

    /** Registered tool names with no call in the last $days days (candidates for pruning from the wide set). */
    public function unused(array $registered, int $days = 60): array
    {
        $stmt = $this->pdo()->prepare('SELECT DISTINCT tool FROM tool_call_log WHERE created_at >= :since');
        $stmt->execute([':since' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        $used = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));

        return array_values(array_filter($registered, static fn (string $n): bool => !isset($used[$n])));
    }

    /** Deletes rows older than $days days. Returns how many. */
    public function prune(int $days = 180): int
    {
        try {
            $stmt = $this->pdo()->prepare('DELETE FROM tool_call_log WHERE created_at < :before');
            $stmt->execute([':before' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }
}
