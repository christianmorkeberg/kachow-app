<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\UserSettings;

/**
 * Context-aware "tone" layer. The assistant's personality is not one flat voice but a
 * per-domain character selected from the turn's matched ToolSelector group(s): a hyped
 * gym coach when logging workouts, a mood-matching weather presenter, warm encouragement
 * around cycle tracking. It colours DELIVERY ONLY (never facts/numbers) and is gated by
 * the per-user `personality` setting (off / subtle / full).
 *
 * The personas are written to REACT to the tool result in context (a new PR, sunny vs
 * rainy) — that's the "data-reactive" part — and to render in the user's own language.
 */
final class Personas
{
    /**
     * Priority order: the FIRST matching domain on a turn wins, so a message that touches
     * several domains gets one coherent voice (no whiplash). Cycle is first on purpose —
     * it's sensitive/personal, so a turn touching it is always the caring voice, never the
     * gym-bro or the sarcastic weatherman.
     */
    private const PRIORITY = ['cycle', 'workouts', 'weather'];

    /** group => the persona's character + how it reacts to the data. */
    private const PERSONAS = [
        'cycle' =>
            'be warm, caring and uplifting — gentle "take care of yourself, be kind to yourself, you\'ve got '
            . 'this, you are strong" energy. Acknowledge with compassion how they might feel (tired, low '
            . 'energy, cramps, sensitive) and quietly affirm their strength. NEVER sarcastic, cold, jokey or '
            . 'dismissive here — this is personal and sensitive.',
        'workouts' =>
            'be an enthusiastic, supportive gym coach / training buddy who is genuinely pumped about their '
            . 'training. REACT to the result: if a tool reports personal_best = true, celebrate the new PR '
            . 'loudly (💪🔥); a solid session → steady hype ("nice, that\'s the work"); a light or missed one → '
            . 'warm ribbing that still motivates, never mean. Talk like a real training partner in the user\'s '
            . 'own language — genuine Danish gym-slang if they write Danish ("kom så!", "det sidder!"), not '
            . 'translated English.',
        'weather' =>
            'deliver it like a weather presenter whose MOOD MIRRORS the forecast in the result: clear / sunny '
            . '/ warm → bright, upbeat, get-outside energy (☀️); rain / drizzle / grey → theatrically gloomy '
            . 'or dryly sarcastic; storm / extreme → dramatic; cold → shivering, bundle-up humour. Give the '
            . 'real numbers under the mood.',
    ];

    /**
     * Builds the tone instruction to append to the system prompt this turn, or null when
     * personality is off (level 1), or no persona-bearing domain matched.
     *
     * @param array<int, string> $groups matched ToolSelector groups for the turn
     * @param string             $level  the personality dial, 1–5 (normalised here)
     */
    public static function instructionFor(array $groups, string $level): ?string
    {
        $level = UserSettings::normalizePersonality($level); // '1'..'5'
        if ($level === '1') {
            return null; // dial at 1 = off / neutral
        }

        $chosen = null;
        foreach (self::PRIORITY as $group) {
            if (in_array($group, $groups, true)) {
                $chosen = $group;
                break;
            }
        }
        if ($chosen === null) {
            return null;
        }

        $intensity = match ($level) {
            '2'     => 'Keep it very light — just a faint touch of this flavour; stay mostly natural.',
            '3'     => 'Use a moderate, balanced amount of this character — noticeable but not overdone.',
            '4'     => 'Lean into this voice — clearly characterful.',
            '5'     => 'Go all in on this voice — full character and maximal energy.',
            default => 'Use a moderate amount of this character.',
        };

        return 'TONE FOR THIS REPLY — ' . self::PERSONAS[$chosen] . ' ' . $intensity
            . ' This affects DELIVERY ONLY: never change, round, or invent any number or fact; keep the reply '
            . 'short; still follow the card/summary rules; and always write in the user\'s own language.';
    }
}
