<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * When to ask "Working from home today?" (location tracking, phase 4 groundwork). Location
 * can't tell a work-from-home day from a day off, so instead of guessing, Kachow asks once,
 * on a weekday morning, when the user is still at a home-type place, hasn't been at a
 * work-type place today and isn't clocked in. Tapping the push opens a chat that asks which
 * client the hours are for (every time) and clocks in. Work-from-home days are loose: errands
 * don't end them — the user does ("I'm done"), with the evening check-out nudge as a backstop.
 */
final class WorkFromHome
{
    /** Local hour window for the prompt (09:00–09:59). */
    public const PROMPT_HOUR = 9;

    /**
     * @param list<array{type:?string, ongoing:bool}> $todayStays stays overlapping today (Timeline::analyse)
     */
    public static function shouldPrompt(array $todayStays, bool $clockedIn, DateTimeImmutable $nowLocal): bool
    {
        if ((int) $nowLocal->format('N') > 5 || (int) $nowLocal->format('G') !== self::PROMPT_HOUR || $clockedIn) {
            return false;
        }
        foreach ($todayStays as $s) {
            if (($s['type'] ?? null) === 'work') {
                return false; // already been at a workplace today
            }
        }
        $current = null;
        foreach ($todayStays as $s) {
            if ($s['ongoing']) {
                $current = $s;
            }
        }

        return $current !== null && ($current['type'] ?? null) === 'home';
    }
}
