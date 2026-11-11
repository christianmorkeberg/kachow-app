<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * What the Gemini API reports per call (token counts) and what that comes to in money. The API never returns a price, so cost = tokens × a per-model price that the
 * developer enters (gemini_prices, in USD per million tokens) and is computed when read — changing a price re-prices the history.
 *
 * Best effort everywhere: a missing table (before the migration) or a failed insert never breaks a Gemini call.
 *
 * Billing shape: INPUT = prompt tokens (the cached part at the cheaper cached price) + tool-use prompt tokens; OUTPUT = reply tokens + thinking tokens.
 */
final class UsageLog
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** The user the current request is working for (set by the chat loop), so a row can say whose it was. Null = unknown / background. */
    private static ?int $user = null;

    public static function forUser(?int $userId): void
    {
        self::$user = $userId;
    }

    public static function currentUser(): ?int
    {
        return self::$user;
    }

    /**
     * Records one call. @param array{prompt:int, cached:int, output:int, thoughts:int, tool:int} $u
     */
    public function record(string $purpose, string $model, array $u, ?int $userId = null): void
    {
        if (($u['prompt'] ?? 0) + ($u['output'] ?? 0) + ($u['thoughts'] ?? 0) + ($u['tool'] ?? 0) === 0) {
            return;   // a call that reported nothing (an error body, a stub) is not a row
        }
        try {
            $this->db->prepare(
                'INSERT INTO gemini_usage (user_id, purpose, model, prompt_tokens, cached_tokens, output_tokens, thought_tokens, tool_tokens)
                 VALUES (:u, :p, :m, :pt, :ct, :ot, :tt, :tl)'
            )->execute([
                ':u' => $userId ?? self::$user, ':p' => mb_substr($purpose, 0, 40), ':m' => mb_substr($model, 0, 80),
                ':pt' => (int) $u['prompt'], ':ct' => (int) $u['cached'], ':ot' => (int) $u['output'], ':tt' => (int) $u['thoughts'], ':tl' => (int) $u['tool'],
            ]);
        } catch (\Throwable $e) {
            error_log('UsageLog::record: ' . $e->getMessage());   // table missing before the migration, or a hiccup: never break the call
        }
    }

    /**
     * Money for a token bundle at a model's prices (USD per million), or null when the model has no price yet.
     *
     * @param array{prompt:int, cached:int, output:int, thoughts:int, tool:int} $u
     * @param array{input_per_m:float, cached_per_m:float, output_per_m:float}|null $price
     */
    public static function cost(array $u, ?array $price): ?float
    {
        if ($price === null) {
            return null;
        }
        $cached  = min((int) $u['cached'], (int) $u['prompt']);
        $fresh   = (int) $u['prompt'] - $cached;
        $input   = $fresh * $price['input_per_m'] + $cached * $price['cached_per_m'] + (int) $u['tool'] * $price['input_per_m'];
        $output  = ((int) $u['output'] + (int) $u['thoughts']) * $price['output_per_m'];

        return ($input + $output) / 1_000_000;
    }

    /** @return array<string, array{input_per_m:float, cached_per_m:float, output_per_m:float}> model → prices */
    public function prices(): array
    {
        try {
            $out = [];
            foreach ($this->db->query('SELECT model, input_per_m, cached_per_m, output_per_m FROM gemini_prices ORDER BY model')->fetchAll() as $r) {
                $out[(string) $r['model']] = ['input_per_m' => (float) $r['input_per_m'], 'cached_per_m' => (float) $r['cached_per_m'], 'output_per_m' => (float) $r['output_per_m']];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function setPrice(string $model, float $input, float $cached, float $output): bool
    {
        $model = trim($model);
        if ($model === '' || mb_strlen($model) > 80 || $input < 0 || $cached < 0 || $output < 0) {
            return false;
        }
        try {
            $this->db->prepare(
                'INSERT INTO gemini_prices (model, input_per_m, cached_per_m, output_per_m) VALUES (:m, :i, :c, :o)
                 ON DUPLICATE KEY UPDATE input_per_m = VALUES(input_per_m), cached_per_m = VALUES(cached_per_m), output_per_m = VALUES(output_per_m)'
            )->execute([':m' => $model, ':i' => $input, ':c' => $cached, ':o' => $output]);

            return true;
        } catch (\Throwable $e) {
            error_log('UsageLog::setPrice: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The developer overview: totals and breakdowns by purpose, by model and by day, each with calls, tokens and the estimated cost (null where a model has no price).
     *
     * @return array<string, mixed>
     */
    public function overview(?int $days): array
    {
        $prices = $this->prices();
        $where  = $days !== null ? 'WHERE created_at >= :since' : '';
        $bind   = $days !== null ? [':since' => gmdate('Y-m-d H:i:s', time() - $days * 86400)] : [];
        $sum    = 'COUNT(*) AS calls, SUM(prompt_tokens) AS prompt, SUM(cached_tokens) AS cached, SUM(output_tokens) AS output, SUM(thought_tokens) AS thoughts, SUM(tool_tokens) AS tool';
        $q = function (string $groupExpr, string $label) use ($where, $bind, $sum) {
            $st = $this->db->prepare("SELECT {$groupExpr} AS k, model, {$sum} FROM gemini_usage {$where} GROUP BY k, model ORDER BY k");
            $st->execute($bind);

            return $st->fetchAll();
        };

        // Aggregate per (key, model) in SQL, price per model in PHP, merge per key.
        $fold = function (array $rows) use ($prices): array {
            $out = [];
            foreach ($rows as $r) {
                $k = (string) $r['k'];
                $u = ['prompt' => (int) $r['prompt'], 'cached' => (int) $r['cached'], 'output' => (int) $r['output'], 'thoughts' => (int) $r['thoughts'], 'tool' => (int) $r['tool']];
                $c = self::cost($u, $prices[(string) $r['model']] ?? null);
                $o = $out[$k] ?? ['key' => $k, 'calls' => 0, 'prompt' => 0, 'cached' => 0, 'output' => 0, 'thoughts' => 0, 'tool' => 0, 'cost' => 0.0, 'unpriced_calls' => 0];
                $o['calls'] += (int) $r['calls'];
                foreach ($u as $f => $v) {
                    $o[$f] += $v;
                }
                if ($c === null) {
                    $o['unpriced_calls'] += (int) $r['calls'];
                } else {
                    $o['cost'] += $c;
                }
                $out[$k] = $o;
            }

            return array_values($out);
        };

        try {
            $byPurpose = $fold($q('purpose', 'purpose'));
            $byModel   = $fold($q('model', 'model'));
            $byDay     = $fold($q('DATE(created_at)', 'day'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'The usage table is not there yet (run the migration 2026-11-11_gemini_usage.sql).', 'prices' => $prices];
        }
        usort($byPurpose, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost'] ?: $b['prompt'] + $b['output'] <=> $a['prompt'] + $a['output']);
        usort($byModel, static fn (array $a, array $b): int => $b['cost'] <=> $a['cost'] ?: $b['prompt'] + $b['output'] <=> $a['prompt'] + $a['output']);
        $tot = ['calls' => 0, 'prompt' => 0, 'cached' => 0, 'output' => 0, 'thoughts' => 0, 'tool' => 0, 'cost' => 0.0, 'unpriced_calls' => 0];
        foreach ($byModel as $m) {
            foreach ($tot as $f => $_) {
                $tot[$f] += $m[$f];
            }
        }

        return ['ok' => true, 'days' => $days, 'totals' => $tot, 'by_purpose' => $byPurpose, 'by_model' => $byModel, 'by_day' => $byDay, 'prices' => $prices];
    }

    /** Deletes rows older than $days (the table is small, but it should not grow forever). */
    public function purge(int $days = 400): int
    {
        try {
            $st = $this->db->prepare('DELETE FROM gemini_usage WHERE created_at < :t');
            $st->execute([':t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

            return $st->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
