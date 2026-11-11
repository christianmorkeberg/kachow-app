<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\Assistant\ToolRanker;
use App\Database;
use PDO;

/**
 * The tool-targeting SHADOW (see ToolRanker): reads the user's tool history and the first-seen dates, stores one row per wide turn, and sums it up for Insights.
 * Changes nothing the user sees. Best effort throughout: before the migration (or on any database hiccup) it simply records nothing.
 */
final class ToolShadow
{
    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    private function pdo(): PDO
    {
        return $this->db ??= Database::get();
    }

    /** The user's tool calls in the ranking window (all entry points). @return list<array{tool:string, at:int}> */
    public function callsFor(int $userId, int $now): array
    {
        $st = $this->pdo()->prepare('SELECT tool, created_at FROM tool_call_log WHERE user_id = :u AND created_at >= :since');
        $st->execute([':u' => $userId, ':since' => gmdate('Y-m-d H:i:s', $now - ToolRanker::WINDOW_DAYS * 86400)]);

        return array_map(static fn (array $r): array => ['tool' => (string) $r['tool'], 'at' => (int) strtotime((string) $r['created_at'] . ' UTC')], $st->fetchAll());
    }

    /**
     * When each current tool was first seen. The FIRST time (empty table) every tool there is counts as old — otherwise all of them would look new for
     * GRACE_DAYS and hide every miss; from then on a tool not seen before is recorded as new now.
     *
     * @param list<string> $names
     * @return array<string, int> tool → unix seconds
     */
    public function firstSeen(array $names, int $now): array
    {
        $known = [];
        foreach ($this->pdo()->query('SELECT tool, first_seen FROM tool_first_seen')->fetchAll() as $r) {
            $known[(string) $r['tool']] = (int) strtotime((string) $r['first_seen'] . ' UTC');
        }
        $stamp   = $known === [] ? '2000-01-01 00:00:00' : gmdate('Y-m-d H:i:s', $now);
        $missing = array_values(array_diff($names, array_keys($known)));
        if ($missing !== []) {
            $ins = $this->pdo()->prepare('INSERT INTO tool_first_seen (tool, first_seen) VALUES (:t, :f)');
            foreach ($missing as $t) {
                $ins->execute([':t' => mb_substr($t, 0, 80), ':f' => $stamp]);
                $known[$t] = (int) strtotime($stamp . ' UTC');
            }
        }

        return $known;
    }

    /** @param array<int, int> $sizes @param list<array<string, mixed>> $called */
    public function record(int $userId, int $totalChars, array $sizes, array $called): void
    {
        $this->pdo()->prepare('INSERT INTO tool_shadow_log (user_id, total_chars, sizes, called) VALUES (:u, :t, :s, :c)')->execute([
            ':u' => $userId, ':t' => $totalChars, ':s' => json_encode($sizes), ':c' => json_encode($called, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * For Insights: per top-N size, how many turns would have MISSED a tool the model then called, and how much smaller the list would have been; plus the
     * tools that would have been missed most (at each size).
     *
     * @return array<string, mixed>
     */
    public function overview(?int $days): array
    {
        try {
            $sql  = 'SELECT total_chars, sizes, called FROM tool_shadow_log' . ($days !== null ? ' WHERE created_at >= :since' : '');
            $st   = $this->pdo()->prepare($sql);
            $st->execute($days !== null ? [':since' => gmdate('Y-m-d H:i:s', time() - $days * 86400)] : []);
            $rows = $st->fetchAll();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The shadow table is not there yet (run the migration 2026-11-11_tool_shadow.sql).'];
        }
        $out = ['ok' => true, 'turns' => count($rows), 'turns_with_tools' => 0, 'full_chars_avg' => 0, 'sizes' => []];
        $full = 0;
        $per  = [];
        foreach (ToolRanker::SIZES as $n) {
            $per[$n] = ['n' => $n, 'missed_turns' => 0, 'chars' => 0, 'missed_tools' => []];
        }
        foreach ($rows as $r) {
            $full  += (int) $r['total_chars'];
            $sizes  = json_decode((string) $r['sizes'], true) ?: [];
            $called = json_decode((string) $r['called'], true) ?: [];
            if ($called !== []) {
                $out['turns_with_tools']++;
            }
            foreach (ToolRanker::SIZES as $n) {
                $per[$n]['chars'] += (int) ($sizes[(string) $n] ?? $sizes[$n] ?? $r['total_chars']);
                $miss = false;
                foreach ($called as $c) {
                    if (!ToolRanker::covered($c, $n)) {
                        $miss = true;
                        $per[$n]['missed_tools'][$c['tool']] = ($per[$n]['missed_tools'][$c['tool']] ?? 0) + 1;
                    }
                }
                $per[$n]['missed_turns'] += $miss ? 1 : 0;
            }
        }
        $t = max(1, count($rows));
        $out['full_chars_avg'] = (int) round($full / $t);
        foreach ($per as $n => $p) {
            arsort($p['missed_tools']);
            $out['sizes'][] = [
                'n'            => $n,
                'missed_turns' => $p['missed_turns'],
                'miss_rate'    => count($rows) > 0 ? round($p['missed_turns'] / count($rows), 4) : null,
                'chars_avg'    => (int) round($p['chars'] / $t),
                'saving'       => $full > 0 ? round(1 - $p['chars'] / $full, 4) : null,
                'missed_tools' => array_slice($p['missed_tools'], 0, 8, true),
            ];
        }

        return $out;
    }

    public function purge(int $days = 180): int
    {
        try {
            $st = $this->pdo()->prepare('DELETE FROM tool_shadow_log WHERE created_at < :t');
            $st->execute([':t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

            return $st->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
