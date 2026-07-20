<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small text-matching helpers used for duplicate detection when adding to lists /
 * collections (dev ideas, shopping, wishlist, …). Catches same-language duplicates
 * (case, punctuation, spacing, typos). Cross-language / reworded duplicates are the
 * model's job — the tools surface the existing entries so it can judge those.
 */
final class TextMatch
{
    /** Lowercases, strips punctuation and collapses whitespace for comparison. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * Whether two strings are the "same" after normalisation, or fuzzily similar at or
     * above $threshold percent (0–100). Good for exact/typo duplicates in one language.
     */
    public static function similar(string $a, string $b, float $threshold = 88.0): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        similar_text($na, $nb, $pct);

        return $pct >= $threshold;
    }
}
