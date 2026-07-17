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

    /** Ignore an AI-generated set older than this (so a broken cron degrades gracefully). */
    private const CACHE_MAX_AGE_DAYS = 4;

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
        // Prefer the daily AI-generated set (personalised + in the user's language).
        $cached = $this->cached($userId);
        if ($cached !== []) {
            return array_slice($cached, 0, $max);
        }

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

    /**
     * The AI-generated set for a user, or [] if none / too stale to trust.
     *
     * @return array<int, string>
     */
    public function cached(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT suggestions FROM quick_action_cache
             WHERE user_id = :u AND generated_at >= (NOW() - INTERVAL ' . self::CACHE_MAX_AGE_DAYS . ' DAY)'
        );
        $stmt->execute([':u' => $userId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $s) {
            $s = trim((string) $s);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** Stores (upserts) a user's AI-generated suggestion set. */
    public function store(int $userId, array $suggestions): void
    {
        $clean = [];
        foreach ($suggestions as $s) {
            $s = trim((string) $s);
            if ($s !== '' && mb_strlen($s) <= 80) {
                $clean[] = mb_substr($s, 0, 80);
            }
            if (count($clean) >= 8) {
                break;
            }
        }
        $json = json_encode(array_values($clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(
            'INSERT INTO quick_action_cache (user_id, suggestions) VALUES (:u, :s)
             ON DUPLICATE KEY UPDATE suggestions = VALUES(suggestions), generated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([':u' => $userId, ':s' => $json]);
    }

    /**
     * The user's recent distinct prompts (newest first) — context for generating
     * fresh starters. Bounded length so a stray essay doesn't dominate.
     *
     * @return array<int, string>
     */
    public function recentPrompts(int $userId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $stmt  = $this->db->prepare(
            "SELECT m.content, MAX(m.id) AS mid
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE c.user_id = :u AND m.role = 'user'
               AND CHAR_LENGTH(TRIM(m.content)) BETWEEN 3 AND 120
             GROUP BY LOWER(TRIM(m.content))
             ORDER BY mid DESC
             LIMIT " . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $t = trim((string) $row['content']);
            if ($t !== '') {
                $out[] = $t;
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
