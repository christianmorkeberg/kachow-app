<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Data-access layer for the `workouts` table.
 *
 * IMPORTANT: one row = one SET, not one session. "Squats 80kg 5x5" is five rows,
 * inserted together by a single logSets() call. Keep this class free of any
 * business logic — Tools/ (LogWorkout, GetWorkoutHistory) translate the model's
 * arguments into these calls.
 */
final class Workouts
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Inserts one row per set and returns the new ids in order.
     *
     * Each $sets entry is an associative array: ['weight' => ?float, 'reps' => ?int,
     * 'notes' => ?string]. Any key may be omitted/null (weight is null for bodyweight
     * exercises). All sets share the same exercise and logged_at timestamp.
     *
     * $loggedAt is a 'Y-m-d H:i:s' string; defaults to now (UTC). The whole batch is
     * atomic — either every set lands or none do.
     *
     * @param array<int, array{weight?: float|null, reps?: int|null, notes?: string|null}> $sets
     * @return int[] inserted row ids
     */
    public function logSets(int $userId, string $exercise, array $sets, ?string $loggedAt = null): array
    {
        if ($sets === []) {
            throw new InvalidArgumentException('logSets requires at least one set.');
        }

        $loggedAt ??= date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO workouts (user_id, exercise, weight, reps, notes, logged_at)
             VALUES (:user_id, :exercise, :weight, :reps, :notes, :logged_at)'
        );

        // Compose with an outer transaction if one is already open; otherwise own one.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $ids = [];
            foreach ($sets as $set) {
                $stmt->execute([
                    ':user_id'   => $userId,
                    ':exercise'  => $exercise,
                    ':weight'    => $set['weight'] ?? null,
                    ':reps'      => $set['reps'] ?? null,
                    ':notes'     => $set['notes'] ?? null,
                    ':logged_at' => $loggedAt,
                ]);
                $ids[] = (int) $this->db->lastInsertId();
            }

            if ($ownTransaction) {
                $this->db->commit();
            }

            return $ids;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Returns individual set rows for a user, newest first.
     *
     * All filters are optional: narrow by exercise and/or a [from, to] date window
     * ('Y-m-d' or 'Y-m-d H:i:s' strings, inclusive). $limit caps the number of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(
        int $userId,
        ?string $exercise = null,
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null
    ): array {
        $sql = 'SELECT id, exercise, weight, reps, notes, logged_at
                FROM workouts
                WHERE user_id = :user_id';
        $params = [':user_id' => $userId];

        if ($exercise !== null) {
            $sql .= ' AND exercise = :exercise';
            $params[':exercise'] = $exercise;
        }
        if ($from !== null) {
            $sql .= ' AND logged_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND logged_at <= :to';
            $params[':to'] = $to;
        }

        $sql .= ' ORDER BY logged_at DESC, id DESC';

        if ($limit !== null) {
            // Cast guarantees an integer literal — safe to inline; avoids the
            // known LIMIT-placeholder friction with non-emulated prepares.
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Updates only the given fields of one of the user's sets. Allowed fields:
     * exercise, weight, reps, notes, logged_at. Returns true if the set exists
     * (and belongs to the user), false otherwise.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $userId, int $id, array $fields): bool
    {
        $allowed = ['exercise', 'weight', 'reps', 'notes', 'logged_at'];

        $set    = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = :{$column}";
                $params[":{$column}"] = $fields[$column];
            }
        }
        if ($set === []) {
            return false;
        }

        $own = $this->db->prepare('SELECT 1 FROM workouts WHERE id = :id AND user_id = :uid');
        $own->execute([':id' => $id, ':uid' => $userId]);
        if ($own->fetchColumn() === false) {
            return false;
        }

        $params[':id']  = $id;
        $params[':uid'] = $userId;
        $stmt = $this->db->prepare(
            'UPDATE workouts SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute($params);

        return true;
    }

    /**
     * Deletes specific set rows by id, scoped to the user (so one user can never
     * delete another's rows). Returns the number of rows removed.
     *
     * @param array<int, int|string> $ids
     */
    public function deleteByIds(int $userId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM workouts WHERE user_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$userId], $ids));

        return $stmt->rowCount();
    }

    /**
     * Deletes all sets of a given exercise for the user, optionally limited to a
     * [from, to] date window (inclusive). Returns the number of rows removed.
     * $exercise is required — this never deletes across all exercises at once.
     */
    public function deleteByExercise(int $userId, string $exercise, ?string $from = null, ?string $to = null): int
    {
        $sql = 'DELETE FROM workouts WHERE user_id = :user_id AND exercise = :exercise';
        $params = [':user_id' => $userId, ':exercise' => $exercise];

        if ($from !== null) {
            $sql .= ' AND logged_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND logged_at <= :to';
            $params[':to'] = $to;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
