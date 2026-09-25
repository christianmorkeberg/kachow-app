<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Training-load arithmetic done in CODE, not by the model: "85% of my bench", "what can I do
 * for 5 reps", "Alex does 70 kg — what's the same for me". The model used to do these in its
 * head, which drifted on which max it used, how it rounded, and the two-step relative case.
 * Pure + static so it is unit-testable without a database (like OneRepMax, whose Epley
 * estimate this inverts for the reps case).
 */
final class TrainingLoad
{
    /** Default loading step: the smallest jump on a standard bar (2 × 1.25 kg plates). */
    public const DEFAULT_STEP = 2.5;

    /** Rounds a load to the nearest loadable step (2.5 kg by default). */
    public static function roundToStep(float $kg, float $step = self::DEFAULT_STEP): float
    {
        if ($step <= 0) {
            return round($kg, 2);
        }

        return round(round($kg / $step) * $step, 2);
    }

    /** Percentage of a max, e.g. 85% of 100 → 85.0 (unrounded). */
    public static function percentOf(float $max, float $percent): float
    {
        return $max * $percent / 100;
    }

    /**
     * The fraction of a 1RM that can be lifted for $reps (inverse Epley: 1RM = w·(1 + reps/30),
     * so w = 1RM / (1 + reps/30)), as a percentage. 1 rep = 100%.
     */
    public static function percentForReps(int $reps): float
    {
        return $reps <= 1 ? 100.0 : 100 / (1 + $reps / 30);
    }

    /** What share of THEIR max a load is (the relative-load step), as a percentage. */
    public static function relativePercent(float $load, float $theirMax): ?float
    {
        return $theirMax > 0 ? $load / $theirMax * 100 : null;
    }

    /**
     * One computed answer: the percentage applied to a max, raw and rounded to the step.
     *
     * @return array{max:float, percent:float, raw:float, load:float}
     */
    public static function apply(float $max, float $percent, float $step = self::DEFAULT_STEP): array
    {
        $raw = self::percentOf($max, $percent);

        return [
            'max'     => round($max, 2),
            'percent' => round($percent, 1),
            'raw'     => round($raw, 2),
            'load'    => self::roundToStep($raw, $step),
        ];
    }
}
