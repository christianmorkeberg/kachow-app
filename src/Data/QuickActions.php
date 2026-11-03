<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Suggests one-tap "quick action" prompts for the empty chat screen.
 *
 * Blends the user's most-repeated past prompts (learned from the messages table,
 * so it self-updates as habits form) with a daily AI-personalised set and a seeded
 * default set (so a fresh user still gets useful buttons). Real repeats lead,
 * since that's the behavior Christian actually wants ("if I often ask for the
 * shopping list, it should appear there"); AI/defaults only fill empty slots.
 *
 * Prompts ending in "…" are templates the UI drops into the input for the user
 * to finish (e.g. "Add to the shopping list…"); the rest send on tap.
 */
class QuickActions
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
     * The chips for the empty chat screen: the AI-written set built from the user's chat
     * history by bin/quick-actions-cron.php, or the seed defaults until that exists
     * (a new user, or a cron that has stopped for longer than CACHE_MAX_AGE_DAYS).
     *
     * @return array<int, string>
     */
    public function suggestions(int $userId, int $max = 6): array
    {
        $out  = [];
        $seen = [];
        foreach ([$this->cached($userId), self::DEFAULTS] as $source) {
            foreach ($source as $text) {
                $key = self::normalize($text);
                if ($key !== '' && !isset($seen[$key]) && count($out) < $max) {
                    $seen[$key] = true;
                    $out[]      = $text;
                }
            }
            if ($out !== []) {
                break; // one source only — never mix AI chips with the seed set
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
        return $this->load($userId, true);
    }

    /**
     * The last stored set regardless of age — fed back to the generator so the chips evolve
     * from day to day instead of being rewritten from scratch.
     *
     * @return array<int, string>
     */
    public function previous(int $userId): array
    {
        return $this->load($userId, false);
    }

    /** @return array<int, string> */
    private function load(int $userId, bool $freshOnly): array
    {
        $stmt = $this->db->prepare(
            'SELECT suggestions FROM quick_action_cache WHERE user_id = :u'
            . ($freshOnly ? ' AND generated_at >= (NOW() - INTERVAL ' . self::CACHE_MAX_AGE_DAYS . ' DAY)' : '')
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
    public function recentPrompts(int $userId, int $limit = 40, ?int $days = null): array
    {
        $limit = max(1, min(300, $limit));
        $since = $days === null ? '' : ' AND m.created_at >= :since';
        $stmt  = $this->db->prepare(
            "SELECT m.content, MAX(m.id) AS mid
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE c.user_id = :u AND m.role = 'user'
               AND CHAR_LENGTH(TRIM(m.content)) BETWEEN 3 AND 120{$since}
             GROUP BY LOWER(TRIM(m.content))
             ORDER BY mid DESC
             LIMIT " . ($limit * 2)
        );
        $params = [':u' => $userId];
        if ($days !== null) {
            $params[':since'] = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        }
        $stmt->execute($params);

        $out  = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $t   = trim((string) $row['content']);
            $key = self::normalize($t);
            if ($key !== '' && !isset($seen[$key]) && !self::isFiller($key)) {
                $seen[$key] = true;
                $out[]      = $t;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /** Retry/confirmation replies ("try again", "prøv igen", "yes") are not useful as starters. */
    private static function isFiller(string $key): bool
    {
        return (bool) preg_match(
            '/^(try again|retry|again|yes|no|ok|okay|thanks|thank you|go ahead|do it|prøv igen|igen|ja|nej|tak|det er fint|gør det)$/u',
            $key
        );
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
