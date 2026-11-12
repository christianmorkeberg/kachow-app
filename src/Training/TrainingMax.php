<?php

declare(strict_types=1);

namespace App\Training;

use App\Data\ExerciseAliases;
use App\Data\Workouts;
use App\Database;
use App\Support\ExerciseNames;
use App\Support\OneRepMax;
use PDO;

/**
 * A person's TRAINING MAX for an exercise — the number a percentage in a programme is a percentage of. It is what they set themselves (an override), else their best TESTED 1-rep
 * max, else their best ESTIMATED 1RM (Epley) from what they logged, else unknown. Names are matched the way each person logs them (their aliases, then their history), so
 * "Bench press" in a shared programme finds Astrid's "Bænkpres" and Christian's "Bench press".
 */
final class TrainingMax
{
    private PDO $db;

    public function __construct(private Workouts $workouts, private ExerciseAliases $aliases, ?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** The exercise as THIS person logs it: their alias, else the name in their history that matches, else the name as given. */
    public function resolveName(int $userId, string $exercise): string
    {
        $name = $this->aliases->resolve($userId, $exercise);
        if ($this->workouts->getHistory($userId, $name, null, null, 1) !== []) {
            return $name;
        }
        $hit = ExerciseNames::matchIn($exercise, $this->workouts->distinctExercises($userId, 60));

        return $hit ?? $name;
    }

    /** @return array{exercise:string, max:?float, basis:?string, tested:?float, estimated:?float} */
    public function forExercise(int $userId, string $exercise): array
    {
        $name = $this->resolveName($userId, $exercise);
        $rec  = OneRepMax::records($this->workouts->getHistory($userId, $name))[0] ?? [];
        $tested    = isset($rec['tested_1rm']['weight']) ? (float) $rec['tested_1rm']['weight'] : null;
        $estimated = isset($rec['est_1rm']['value']) ? (float) $rec['est_1rm']['value'] : null;
        $override  = $this->override($userId, $name);
        [$max, $basis] = $override !== null ? [$override, 'override'] : ($tested !== null ? [$tested, 'tested'] : ($estimated !== null ? [$estimated, 'estimated'] : [null, null]));

        return ['exercise' => $name, 'max' => $max, 'basis' => $basis, 'tested' => $tested, 'estimated' => $estimated];
    }

    public function override(int $userId, string $resolvedExercise): ?float
    {
        try {
            $st = $this->db->prepare('SELECT training_max FROM training_maxes WHERE user_id = :u AND exercise = :e');
            $st->execute([':u' => $userId, ':e' => $resolvedExercise]);
            $v = $st->fetchColumn();

            return $v === false ? null : (float) $v;
        } catch (\Throwable $e) {
            return null;   // before the migration
        }
    }

    /** Sets (or, with null / 0, clears) the person's training max for an exercise. Returns false when it could not be saved. */
    public function setOverride(int $userId, string $exercise, ?float $max): bool
    {
        $name = $this->resolveName($userId, $exercise);
        try {
            if ($max === null || $max <= 0) {
                $this->db->prepare('DELETE FROM training_maxes WHERE user_id = :u AND exercise = :e')->execute([':u' => $userId, ':e' => $name]);

                return true;
            }
            if ($max > 1000) {
                return false;
            }
            $del = $this->db->prepare('DELETE FROM training_maxes WHERE user_id = :u AND exercise = :e');
            $del->execute([':u' => $userId, ':e' => $name]);
            $this->db->prepare('INSERT INTO training_maxes (user_id, exercise, training_max) VALUES (:u, :e, :m)')->execute([':u' => $userId, ':e' => $name, ':m' => round($max, 2)]);

            return true;
        } catch (\Throwable $e) {
            error_log('TrainingMax::setOverride: ' . $e->getMessage());

            return false;
        }
    }
}
