<?php

declare(strict_types=1);

namespace App\Assistant;

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
     * personality is off, the level is unknown, or no persona-bearing domain matched.
     *
     * @param array<int, string> $groups matched ToolSelector groups for the turn
     */
    public static function instructionFor(array $groups, string $level): ?string
    {
        $level = strtolower(trim($level));
        if ($level === 'off' || $level === '') {
            return null;
        }
        if (!in_array($level, ['subtle', 'full'], true)) {
            $level = 'subtle'; // defensive: any unexpected stored value → the gentle default
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

        $intensity = $level === 'full'
            ? 'Lean into this voice fully.'
            : 'Keep it light — a touch of this flavour, a word or two of character; stay mostly natural and '
                . 'do not overdo it.';

        return 'TONE FOR THIS REPLY — ' . self::PERSONAS[$chosen] . ' ' . $intensity
            . ' This affects DELIVERY ONLY: never change, round, or invent any number or fact; keep the reply '
            . 'short; still follow the card/summary rules; and always write in the user\'s own language.';
    }
}
