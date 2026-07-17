<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\QuickActions;

/**
 * Generates fresh "conversation starter" chips for a user's empty chat screen from
 * their recent messages, using a cheap model. Run daily by cron so the chips stay
 * personal and in the user's own language (Danish for Alex, English for Christian)
 * instead of a static English seed set. Stores the result via QuickActions.
 */
final class QuickActionGenerator
{
    private const SYSTEM =
        'You generate up to 6 short "conversation starter" chips for the home screen of a personal '
        . 'assistant app. Base them on the user\'s RECENT MESSAGES (match their LANGUAGE and interests) '
        . 'and the assistant\'s CAPABILITIES. Rules: each chip is 2–6 words; in the SAME language the '
        . 'user writes in; action-oriented and specific; varied (do not repeat a topic); natural with no '
        . 'ending punctuation, EXCEPT a chip that needs the user to add detail, which must end with "…" '
        . '(e.g. "Tilføj til indkøbslisten…" or "Log a workout…"). Prefer things the user actually does; '
        . 'you may include one useful capability they have not tried. Return ONLY a JSON array of strings.';

    private const CAPABILITIES =
        'shopping & to-do lists, workouts & training plans, calendar, weather, expenses/receipts, '
        . 'work hours, work log, menstrual cycle + mood/energy, email, vinyl collection';

    public function __construct(
        private GeminiClient $gemini,
        private QuickActions $quickActions,
    ) {
    }

    /**
     * Generates and stores starters for a user. Returns the stored list (or [] if
     * there wasn't enough history, or generation failed — the screen then falls back
     * to frequent/default chips).
     *
     * @return array<int, string>
     */
    public function generateFor(int $userId): array
    {
        $recent = $this->quickActions->recentPrompts($userId, 40);
        if (count($recent) < 3) {
            return []; // too little history to personalise — leave the defaults
        }

        $prompt = 'CAPABILITIES: ' . self::CAPABILITIES . "\n\nRECENT MESSAGES (newest first):\n- "
            . implode("\n- ", array_map(static fn (string $s): string => mb_substr($s, 0, 120), $recent));

        try {
            $models   = $this->gemini->models();
            $cheapest = end($models) ?: null;
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                [],
                self::SYSTEM,
                $cheapest,
                [
                    'temperature'      => 0.8, // a little variety day to day
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
            );
            $parsed = json_decode(GeminiClient::extractText($response), true);
        } catch (\Throwable $e) {
            error_log('QuickActionGenerator: ' . $e->getMessage());
            return [];
        }

        $clean = $this->clean(is_array($parsed) ? $parsed : []);
        if ($clean === []) {
            return [];
        }

        $this->quickActions->store($userId, $clean);

        return $clean;
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, string>
     */
    private function clean(array $raw): array
    {
        $out  = [];
        $seen = [];
        foreach ($raw as $item) {
            $s = trim((string) $item);
            $s = trim($s, " \t\"'“”‘’");
            $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
            if ($s === '' || mb_strlen($s) > 60) {
                continue;
            }
            $key = mb_strtolower(rtrim($s, " .!?…"));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $s;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }
}
