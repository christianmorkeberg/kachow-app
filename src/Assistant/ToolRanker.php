<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Which tools a turn would send if the tool list were TARGETED instead of complete (today every call sends all ~190 declarations, ~200,000 characters).
 * Pure: the shadow (Diagnostics\ToolShadow) feeds it the user's tool history and the current list, and checks afterwards whether the tools the model really called
 * would have been there — before anything is switched.
 *
 * The would-be set is the union of:
 *   - MATCHED: the tools of the areas the message (and the turns just before it) is about — relevance, so a rare "export my data" still finds export;
 *   - TOP N:   the user's most used tools, recent use weighing more (half-life HALF_LIFE_DAYS over WINDOW_DAYS) — the everyday set, also for chaining across areas;
 *   - NEW:     a tool first seen less than GRACE_DAYS ago — a fair chance before it has any history;
 *   - CORE:    a handful that must always be there (remembering facts, the dev-idea offer, running a routine by its phrase, "what can you do").
 * The size of the top set is not fixed yet: the shadow logs each called tool's RANK, so the miss rate for any N can be read afterwards (SIZES are the ones shown).
 */
final class ToolRanker
{
    public const HALF_LIFE_DAYS = 14;
    public const WINDOW_DAYS    = 60;
    public const GRACE_DAYS     = 21;
    public const SIZES          = [10, 20, 30, 40];
    public const CORE           = ['remember_about_me', 'note_dev_idea', 'run_routine', 'explain_capabilities'];

    /** Never judged here: the web tools are opt-in per turn, by their own consent rule. */
    public const IGNORED = ['web_search', 'fetch_webpage'];

    /**
     * A score per tool from its calls: each call counts 0.5^(age in days / half-life), calls older than the window not at all. Highest first.
     *
     * @param list<array{tool:string, at:int}> $calls unix seconds
     * @return array<string, float>
     */
    public static function scores(array $calls, int $now): array
    {
        $out = [];
        foreach ($calls as $c) {
            $age = max(0, $now - (int) $c['at']) / 86400;
            if ($age > self::WINDOW_DAYS) {
                continue;
            }
            $out[$c['tool']] = ($out[$c['tool']] ?? 0.0) + 0.5 ** ($age / self::HALF_LIFE_DAYS);
        }
        arsort($out);

        return $out;
    }

    /** @param array<string, float> $scores @return array<string, int> tool → rank (1 = most used) */
    public static function ranks(array $scores): array
    {
        $r = [];
        $i = 0;
        foreach (array_keys($scores) as $tool) {
            $r[$tool] = ++$i;
        }

        return $r;
    }

    /**
     * The would-be sets for this turn, and how big each would be.
     *
     * @param array<string, int> $sizes     every current tool → its declaration size in chars
     * @param list<string>       $matched   tools of the matched areas
     * @param array<string, int> $ranks     tool → rank
     * @param array<string, int> $firstSeen tool → unix seconds it was first seen
     * @return array{matched: list<string>, new: list<string>, core: list<string>, total: int, sizes: array<int, int>}
     */
    public static function plan(array $sizes, array $matched, array $ranks, array $firstSeen, int $now): array
    {
        $exists  = static fn (array $names): array => array_values(array_filter(array_unique($names), static fn (string $n): bool => isset($sizes[$n])));
        $matched = $exists($matched);
        $core    = $exists(self::CORE);
        $new     = $exists(array_keys(array_filter($firstSeen, static fn (int $at): bool => $now - $at < self::GRACE_DAYS * 86400)));
        $base    = array_fill_keys(array_merge($matched, $core, $new), true);
        $bySize  = [];
        foreach (self::SIZES as $n) {
            $set = $base;
            foreach ($ranks as $tool => $rank) {
                if ($rank <= $n && isset($sizes[$tool])) {
                    $set[$tool] = true;
                }
            }
            $bySize[$n] = array_sum(array_map(static fn (string $t): int => $sizes[$t], array_keys($set)));
        }

        return ['matched' => $matched, 'new' => $new, 'core' => $core, 'total' => array_sum($sizes), 'sizes' => $bySize];
    }

    /**
     * For each tool the model really called: would it have been there, and why (matched / core / new / its rank)?
     *
     * @param array{matched: list<string>, new: list<string>, core: list<string>} $plan
     * @param array<string, int> $ranks
     * @param list<string>       $called
     * @return list<array{tool:string, rank:?int, matched:bool, core:bool, new:bool}>
     */
    public static function evaluate(array $plan, array $ranks, array $called): array
    {
        $out = [];
        foreach (array_values(array_unique($called)) as $tool) {
            if (in_array($tool, self::IGNORED, true)) {
                continue;
            }
            $out[] = [
                'tool'    => $tool,
                'rank'    => $ranks[$tool] ?? null,
                'matched' => in_array($tool, $plan['matched'], true),
                'core'    => in_array($tool, $plan['core'], true),
                'new'     => in_array($tool, $plan['new'], true),
            ];
        }

        return $out;
    }

    /** Would this called tool have been in the set with a top N of $n? @param array{tool:string, rank:?int, matched:bool, core:bool, new:bool} $c */
    public static function covered(array $c, int $n): bool
    {
        return $c['matched'] || $c['core'] || $c['new'] || ($c['rank'] !== null && $c['rank'] <= $n);
    }
}
