<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Computes lift records from raw set rows so the MODEL doesn't have to eyeball sets and
 * do error-prone 1RM arithmetic (which led to confabulated dates/reps and mixed-up
 * numbers). Per exercise it returns the heaviest set, the best TESTED 1RM (an actual
 * 1-rep max), and the best ESTIMATED 1RM (Epley) with its source set.
 */
final class OneRepMax
{
    /** Epley estimate. A 1-rep set is its own 1RM (no inflation). */
    public static function estimate(float $weight, int $reps): float
    {
        return $reps <= 1 ? $weight : $weight * (1 + $reps / 30);
    }

    /**
     * @param array<int, array<string, mixed>> $sets rows with exercise, weight, reps, logged_at
     * @return array<int, array<string, mixed>> one record per exercise present (weighted sets only)
     */
    public static function records(array $sets): array
    {
        $byExercise = [];
        foreach ($sets as $s) {
            $weight = isset($s['weight']) && $s['weight'] !== null ? (float) $s['weight'] : null;
            if ($weight === null) {
                continue; // bodyweight — no load to rank
            }
            $exercise = (string) ($s['exercise'] ?? '');
            $byExercise[$exercise][] = [
                'weight' => $weight,
                'reps'   => isset($s['reps']) && $s['reps'] !== null ? (int) $s['reps'] : null,
                'date'   => substr((string) ($s['logged_at'] ?? ''), 0, 10),
            ];
        }

        $out = [];
        foreach ($byExercise as $exercise => $list) {
            $heaviest = null;
            $tested   = null; // heaviest 1-rep set
            $best     = null; // best Epley estimate

            foreach ($list as $r) {
                if ($heaviest === null || $r['weight'] > $heaviest['weight']
                    || ($r['weight'] === $heaviest['weight'] && (int) $r['reps'] > (int) $heaviest['reps'])) {
                    $heaviest = $r;
                }
                if ($r['reps'] === 1 && ($tested === null || $r['weight'] > $tested['weight'])) {
                    $tested = $r;
                }
                if ($r['reps'] !== null && $r['reps'] >= 1) {
                    $est = self::estimate($r['weight'], $r['reps']);
                    if ($best === null || $est > $best['est']) {
                        $best = ['est' => $est] + $r;
                    }
                }
            }

            $record = ['exercise' => $exercise];
            if ($heaviest !== null) {
                $record['heaviest'] = ['weight' => $heaviest['weight'], 'reps' => $heaviest['reps'], 'date' => $heaviest['date']];
            }
            $record['tested_1rm'] = $tested !== null
                ? ['weight' => $tested['weight'], 'date' => $tested['date']]
                : null;
            if ($best !== null) {
                $record['est_1rm'] = [
                    'value'       => round($best['est'], 1),
                    'from_weight' => $best['weight'],
                    'from_reps'   => $best['reps'],
                    'date'        => $best['date'],
                ];
            }
            $out[] = $record;
        }

        return $out;
    }
}
