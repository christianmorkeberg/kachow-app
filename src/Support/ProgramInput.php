<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reading what a model hands to the programme tools, and showing a programme day as one line. The user's note can be in any shape —
 * the model understands it — so this side is strict and forgiving about the small things: weekday names in English and Danish, a week
 * given as 43 / "43" / "uge 43", a year inferred from the calendar. Pure.
 */
final class ProgramInput
{
    private const DAYS = [
        'mon' => 1, 'monday' => 1, 'man' => 1, 'mandag' => 1, 'tue' => 2, 'tues' => 2, 'tuesday' => 2, 'tir' => 2, 'tirs' => 2, 'tirsdag' => 2,
        'wed' => 3, 'wednesday' => 3, 'ons' => 3, 'onsdag' => 3, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4, 'tor' => 4, 'tors' => 4, 'torsdag' => 4,
        'fri' => 5, 'friday' => 5, 'fre' => 5, 'fredag' => 5, 'sat' => 6, 'saturday' => 6, 'lør' => 6, 'lørdag' => 6, 'loer' => 6, 'lordag' => 6,
        'sun' => 7, 'sunday' => 7, 'søn' => 7, 'søndag' => 7, 'soen' => 7, 'sondag' => 7,
    ];

    /** A weekday as 1 (Monday) … 7 (Sunday) from a number or a name, or null. */
    public static function weekday(mixed $v): ?int
    {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
            $n = (int) $v;

            return $n >= 1 && $n <= 7 ? $n : null;
        }

        return self::DAYS[rtrim(mb_strtolower(trim((string) $v)), '.')] ?? null;
    }

    /** A week number from 43, "43", "uge 43", "week 43", "w43", or null. */
    public static function week(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 1 && $v <= 60 ? $v : null;
        }
        if (is_string($v) && preg_match('/(\d{1,2})\s*$/', trim($v), $m) === 1) {
            $n = (int) $m[1];

            return $n >= 1 && $n <= 60 ? $n : null;
        }

        return null;
    }

    /**
     * The ISO year to pin a calendar week to when the user said only "uge 43": this year's, unless that week is clearly behind us
     * (more than a month ago), then next year's.
     */
    public static function inferYear(int $week, ?DateTimeImmutable $now = null, string $tz = 'Europe/Copenhagen'): int
    {
        $now  = $now ?? new DateTimeImmutable('now', new DateTimeZone($tz));
        $year = (int) $now->format('o');
        $cur  = (int) $now->format('W');

        return $week >= $cur - 4 ? $year : $year + 1;
    }

    /**
     * "Deadlift 1×4 @ 117 kg — alt: pull-ups" for a programme item.
     *
     * @param array{exercise:string, sets:?int, reps:?int, weight:?float, note:?string} $i
     */
    public static function itemLabel(array $i): string
    {
        $bits = [];
        if ($i['sets'] !== null && $i['reps'] !== null) {
            $bits[] = $i['sets'] . '×' . $i['reps'];
        } elseif ($i['sets'] !== null) {
            $bits[] = $i['sets'] . ' sets';
        } elseif ($i['reps'] !== null) {
            $bits[] = $i['reps'] . ' reps';
        }
        if ($i['weight'] !== null) {
            $w = rtrim(rtrim(number_format($i['weight'], 2, '.', ''), '0'), '.');
            $bits[] = '@ ' . $w . ' kg';
        }
        $line = $i['exercise'] . ($bits !== [] ? ' ' . implode(' ', $bits) : '');

        return $i['note'] !== null && $i['note'] !== '' ? $line . ' — ' . $i['note'] : $line;
    }

    /** Whether a saved note looks like a training programme (weekdays + sets / reps / kg, or week numbers + exercises) — for finding one in memory. */
    public static function looksLikeProgramme(string $text): bool
    {
        $t = mb_strtolower($text);
        $days = 0;
        foreach (['mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $d) {
            if (str_contains($t, $d)) {
                $days++;
            }
        }
        $numbers = preg_match_all('/\d+\s*[x×]\s*\d+|\d+\s*kg|\d+\s*(sæt|sets|reps|gentagelser)/u', $t) ?: 0;
        $weeks   = preg_match_all('/\b(uge|week|uke|w)\s*\d{1,2}\b/u', $t) ?: 0;
        $lifts   = 0;
        foreach (['dødløft', 'deadlift', 'squat', 'bænkpres', 'bench', 'skulderpres', 'lat pulldown', 'lunge', 'curl', 'pullup', 'pull-up', 'row', 'træning', 'training', 'workout'] as $w) {
            if (str_contains($t, $w)) {
                $lifts++;
            }
        }

        return ($days >= 2 && ($numbers >= 2 || $lifts >= 2)) || ($weeks >= 1 && $lifts >= 2) || ($numbers >= 4 && $lifts >= 2);
    }
}
