<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Suggests one-tap "quick action" prompts for the empty chat screen.
 *
 * Blends the user's most-repeated past prompts (learned from the messages table,
 * so it self-updates as habits form) with a seeded default set (so a fresh user
 * still gets useful buttons). Frequent ones lead; defaults fill the rest.
 *
 * Prompts ending in "…" are templates the UI drops into the input for the user
 * to finish (e.g. "Add to the shopping list…"); the rest send on tap.
 */
final class QuickActions
{
    /** Seed set shown until the user's own habits take over. */
    public const DEFAULTS = [
        "What's on the shopping list?",
        'Add to the shopping list…',
        "What's on my calendar today?",
        'Log a workout…',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Blended suggestions: frequent-first, then defaults, de-duplicated, capped.
     *
     * @return array<int, string>
     */
    public function suggestions(int $userId, int $max = 6): array
    {
        $out  = [];
        $seen = [];
        $take = static function (string $text) use (&$out, &$seen, $max): void {
            $key = self::normalize($text);
            if ($key !== '' && !isset($seen[$key]) && count($out) < $max) {
                $seen[$key] = true;
                $out[]      = $text;
            }
        };

        foreach ($this->frequent($userId, $max) as $f) {
            $take($f);
        }
        foreach (self::DEFAULTS as $d) {
            $take($d);
        }

        return $out;
    }

    /**
     * The user's most-repeated prompts (normalized), most-used first.
     *
     * @return array<int, string>
     */
    public function frequent(int $userId, int $limit = 6, int $minCount = 2, int $minLen = 8, int $maxLen = 80): array
    {
        // Numeric bounds are cast + inlined (LIMIT/HAVING placeholders are brittle
        // under EMULATE_PREPARES=false); only the user id is bound.
        $limit    = max(1, (int) $limit);
        $minCount = max(2, (int) $minCount);
        $minLen   = max(1, (int) $minLen);
        $maxLen   = max($minLen, (int) $maxLen);

        $sql = "SELECT MAX(m.content) AS content, COUNT(*) AS n
                FROM messages m
                JOIN conversations c ON c.id = m.conversation_id
                WHERE c.user_id = :uid
                  AND m.role = 'user'
                  AND CHAR_LENGTH(TRIM(m.content)) BETWEEN {$minLen} AND {$maxLen}
                GROUP BY LOWER(TRIM(m.content))
                HAVING COUNT(*) >= {$minCount}
                ORDER BY n DESC, MAX(m.id) DESC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $text = trim((string) $row['content']);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    private static function normalize(string $text): string
    {
        // Lowercase, strip trailing punctuation/ellipsis + collapse spaces, so
        // "Add to the shopping list…" and "add to the shopping list" don't double up.
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[\s]+/u', ' ', $t) ?? $t;

        return rtrim($t, " .!?…");
    }
}
