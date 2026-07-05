<?php

declare(strict_types=1);

namespace App\Assistant;

/**
 * Chooses which stored personal facts to inject into the system prompt for a
 * given message, so the prompt stays small once a user has accumulated a lot of
 * memory.
 *
 * Deterministic, no embeddings/API: below a budget we inject everything (no loss);
 * above it we always keep the most-recent facts (identity stays fresh) and fill the
 * rest with the facts whose words overlap the current message. Same pragmatic,
 * testable spirit as ToolSelector.
 *
 * Limitation: lexical overlap can miss a semantically-relevant fact with no shared
 * words (e.g. "shellfish" vs. a "what's for dinner?" question). Acceptable while
 * memory is modest; an embedding-based ranker is the upgrade path if recall matters.
 */
final class MemorySelector
{
    /** Max facts to inject once selection kicks in. */
    public const DEFAULT_BUDGET = 24;

    /** Of the budget, always keep this many most-recent facts. */
    public const DEFAULT_RECENT = 8;

    /** Short words that carry no selection signal. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'you', 'your', 'are', 'was', 'were', 'that', 'this',
        'with', 'have', 'has', 'had', 'what', 'when', 'where', 'who', 'why', 'how',
        'can', 'could', 'would', 'should', 'will', 'about', 'into', 'from', 'they',
        'them', 'their', 'but', 'not', 'any', 'all', 'get', 'got', 'out', 'now',
        'did', 'does', 'done', 'some', 'like', 'just', 'than', 'then', 'there',
    ];

    /**
     * @param array<int, array{id?: int, category?: string, content?: string}> $facts  chronological (oldest first)
     * @return array<int, array{id?: int, category?: string, content?: string}>         subset, chronological order preserved
     */
    public static function select(
        array $facts,
        string $message,
        int $budget = self::DEFAULT_BUDGET,
        int $recentKeep = self::DEFAULT_RECENT,
    ): array {
        $n = count($facts);
        if ($n <= $budget) {
            return $facts; // small enough — inject everything, nothing lost
        }

        $tokens = self::tokens($message);

        // Always keep the most-recent facts (the tail of the chronological list).
        $keep = [];
        for ($i = max(0, $n - $recentKeep); $i < $n; $i++) {
            $keep[$i] = true;
        }

        // Rank the remaining facts by message-word overlap (ties: newer first),
        // and fill the rest of the budget.
        $candidates = [];
        foreach ($facts as $i => $fact) {
            if (!isset($keep[$i])) {
                $candidates[$i] = self::score($tokens, (string) ($fact['content'] ?? ''));
            }
        }
        uksort($candidates, static function (int $a, int $b) use ($candidates): int {
            return $candidates[$b] <=> $candidates[$a] ?: $b <=> $a;
        });

        $slots = max(0, $budget - count($keep));
        foreach (array_keys($candidates) as $i) {
            if ($slots <= 0) {
                break;
            }
            $keep[$i] = true;
            $slots--;
        }

        // Emit kept facts in the original chronological order for a stable prompt.
        $out = [];
        foreach ($facts as $i => $fact) {
            if (isset($keep[$i])) {
                $out[] = $fact;
            }
        }

        return $out;
    }

    /**
     * How many distinct message tokens appear in the fact's text.
     *
     * @param array<int, string> $tokens
     */
    private static function score(array $tokens, string $content): int
    {
        if ($tokens === []) {
            return 0;
        }
        $haystack = ' ' . mb_strtolower($content) . ' ';
        $hits = 0;
        foreach ($tokens as $t) {
            if (str_contains($haystack, $t)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * Lowercased, de-duplicated content words (>= 3 chars, non-stopword).
     *
     * @return array<int, string>
     */
    private static function tokens(string $message): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && !in_array($w, self::STOPWORDS, true)) {
                $out[$w] = true;
            }
        }

        return array_keys($out);
    }
}
