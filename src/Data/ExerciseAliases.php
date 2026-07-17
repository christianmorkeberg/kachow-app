<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `exercise_aliases` — per-user canonicalisation of free-text
 * exercise names. Exercise names are the grouping key for workout history and the
 * progression chart, so variants of the same lift (Deadlift / Dødløft / Dead lift,
 * Squat / Squats / Backsquat) otherwise fragment the data.
 *
 * An alias maps a NORMALISED name (lowercased/trimmed/space-collapsed) to the user's
 * chosen canonical display name. resolve() is applied when logging (so future sets
 * land canonical) and when querying by exercise (so a query variant finds the rows).
 * The actual re-labelling of existing rows lives in Workouts::renameExercises.
 */
final class ExerciseAliases
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Normalises a name into its alias key: lowercased, trimmed, internal whitespace
     * collapsed to single spaces. So "Dead  Lift ", "dead lift" → "dead lift".
     */
    public static function normalize(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        return mb_strtolower($name);
    }

    /**
     * Resolves a name to its canonical form for the user, or returns the trimmed input
     * unchanged when no alias is registered. Cheap single-row lookup.
     */
    public function resolve(int $userId, string $name): string
    {
        $trimmed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
        if ($trimmed === '') {
            return $trimmed;
        }

        $stmt = $this->db->prepare(
            'SELECT canonical FROM exercise_aliases WHERE user_id = :uid AND alias_norm = :norm'
        );
        $stmt->execute([':uid' => $userId, ':norm' => self::normalize($name)]);
        $canonical = $stmt->fetchColumn();

        return $canonical !== false ? (string) $canonical : $trimmed;
    }

    /**
     * Registers (or re-points) an alias for the user. A no-op when the alias equals the
     * canonical name after normalisation (nothing to remap).
     */
    public function setAlias(int $userId, string $alias, string $canonical): void
    {
        $canonical = preg_replace('/\s+/u', ' ', trim($canonical)) ?? trim($canonical);
        $norm      = self::normalize($alias);
        if ($canonical === '' || $norm === '' || $norm === self::normalize($canonical)) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO exercise_aliases (user_id, alias_norm, canonical)
             VALUES (:uid, :norm, :canon)
             ON DUPLICATE KEY UPDATE canonical = VALUES(canonical)'
        );
        $stmt->execute([':uid' => $userId, ':norm' => $norm, ':canon' => $canonical]);
    }

    /**
     * Returns the user's registered aliases as [alias_norm => canonical], newest first.
     *
     * @return array<string, string>
     */
    public function all(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT alias_norm, canonical FROM exercise_aliases WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['alias_norm']] = (string) $row['canonical'];
        }

        return $out;
    }
}
