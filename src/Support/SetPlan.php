<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a programme exercise prescribes, set by set. An exercise is either UNIFORM (sets × reps at one percent of the person's training max, or one fixed weight)
 * or a RAMP — a per-set plan like 3 × 3 at 50 / 60 / 70 %. Pure: it turns what the model or the user hands in into one stored shape, expands it into one target per set
 * for the Train card, and says it in one line. A percentage is of the person's OWN training max, which is what lets two people share one schedule.
 */
final class SetPlan
{
    public const MAX_SETS = 99;

    /**
     * Normalises one exercise's targets. `set_plan` (a list of {reps, percent, weight}) wins over the uniform fields; a plan whose sets are all the same collapses back to uniform.
     *
     * @param array<string, mixed> $ex as sent: sets, reps, weight, percent, set_plan
     * @return array{sets:?int, reps:?int, weight:?float, percent:?float, set_plan:?list<array{reps:?int, percent:?float, weight:?float}>}
     */
    public static function normalize(array $ex): array
    {
        $plan = [];
        if (is_array($ex['set_plan'] ?? null)) {
            foreach (array_slice(array_values($ex['set_plan']), 0, self::MAX_SETS) as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $plan[] = ['reps' => self::reps($s['reps'] ?? null), 'percent' => self::percent($s['percent'] ?? null), 'weight' => self::weight($s['weight'] ?? null)];
            }
        }
        if (count($plan) >= 2) {
            $first = $plan[0];
            if (count(array_filter($plan, static fn (array $s): bool => $s === $first)) === count($plan)) {
                return ['sets' => count($plan), 'reps' => $first['reps'], 'weight' => $first['weight'], 'percent' => $first['percent'], 'set_plan' => null];
            }

            return ['sets' => count($plan), 'reps' => null, 'weight' => null, 'percent' => null, 'set_plan' => $plan];
        }
        if (count($plan) === 1) {
            $ex = ['sets' => 1] + $plan[0];   // one set: just a uniform exercise
        }

        return [
            'sets'     => self::sets($ex['sets'] ?? null),
            'reps'     => self::reps($ex['reps'] ?? null),
            'weight'   => self::weight($ex['weight'] ?? null),
            'percent'  => self::percent($ex['percent'] ?? null),
            'set_plan' => null,
        ];
    }

    /**
     * One target per set.
     *
     * @param array<string, mixed> $item a stored item: sets, reps, weight, percent, set_plan
     * @return list<array{reps:?int, percent:?float, weight:?float}>
     */
    public static function expand(array $item): array
    {
        if (is_array($item['set_plan'] ?? null) && $item['set_plan'] !== []) {
            return array_map(static fn ($s): array => [   // from JSON the numbers may be ints: one type for every consumer
                'reps'    => isset($s['reps']) ? (int) $s['reps'] : null,
                'percent' => isset($s['percent']) ? (float) $s['percent'] : null,
                'weight'  => isset($s['weight']) ? (float) $s['weight'] : null,
            ], array_values($item['set_plan']));
        }
        $n = max(1, (int) ($item['sets'] ?? 1));

        return array_fill(0, min($n, self::MAX_SETS), [
            'reps'    => isset($item['reps']) ? (int) $item['reps'] : null,
            'percent' => isset($item['percent']) ? (float) $item['percent'] : null,
            'weight'  => isset($item['weight']) ? (float) $item['weight'] : null,
        ]);
    }

    /** "3 × 5 · 75%", "3 × 3 · 50 / 60 / 70%", "4 × 8 · 60 kg", "3 × 8" — what the schedule shows for the exercise. @param array<string, mixed> $item */
    public static function label(array $item): string
    {
        $sets = self::expand($item);
        $n    = count($sets);
        $reps = array_values(array_unique(array_map(static fn (array $s): ?int => $s['reps'], $sets), SORT_REGULAR));
        $pcts = array_map(static fn (array $s): ?float => $s['percent'], $sets);
        $wts  = array_map(static fn (array $s): ?float => $s['weight'], $sets);
        $bits = [];
        if (count($reps) === 1) {
            $bits[] = ($item['sets'] ?? null) === null && !is_array($item['set_plan'] ?? null)
                ? ($reps[0] !== null ? $reps[0] . ' reps' : '')
                : $n . ' × ' . ($reps[0] ?? '?');
        } else {
            $bits[] = implode(' / ', array_map(static fn (?int $r): string => $r === null ? '?' : (string) $r, array_map(static fn (array $s): ?int => $s['reps'], $sets)));
        }
        $joinNum = static fn (array $v, string $unit): ?string => count(array_filter($v, static fn ($x): bool => $x !== null)) === 0 ? null
            : implode(' / ', array_map(static fn ($x): string => $x === null ? '–' : rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.'), count(array_unique($v, SORT_REGULAR)) === 1 ? [$v[0]] : $v)) . $unit;
        if (($p = $joinNum($pcts, '%')) !== null) {
            $bits[] = $p;
        } elseif (($w = $joinNum($wts, ' kg')) !== null) {
            $bits[] = $w;
        }

        return implode(' · ', array_filter($bits, static fn (string $b): bool => $b !== ''));
    }

    private static function sets(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= self::MAX_SETS ? (int) $v : null;
    }

    private static function reps(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v >= 1 && (int) $v <= 999 ? (int) $v : null;
    }

    private static function weight(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v >= 0 && (float) $v <= 1000 ? (float) $v : null;
    }

    /** A percentage of the training max: 1–150 (heavier than 100 is real: a negative, a supramaximal hold). */
    private static function percent(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v >= 1 && (float) $v <= 150 ? round((float) $v, 2) : null;
    }
}
