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

    private ?bool $sessionCols = null;

    /** Whether the shared-training migration has added session_id / set_no / planned_* to workouts (before it, sets are logged without them). */
    public function hasSessionColumns(): bool
    {
        if ($this->sessionCols === null) {
            try {
                $this->db->query('SELECT session_id, set_no, planned_percent, planned_weight FROM workouts LIMIT 0');
                $this->sessionCols = true;
            } catch (Throwable $e) {
                $this->sessionCols = false;
            }
        }

        return $this->sessionCols;
    }

    /**
     * One set from the Train card: tied to a training session and its place in it (set_no), with what was PLANNED next to what was done. Without the migration it is
     * logged as a plain set. @param array{weight:?float, reps:?int, notes:?string, session_id:int, set_no:int, planned_percent:?float, planned_weight:?float} $set
     */
    public function logPlannedSet(int $userId, string $exercise, array $set, ?string $loggedAt = null): int
    {
        if (!$this->hasSessionColumns()) {
            return $this->logSets($userId, $exercise, [['weight' => $set['weight'], 'reps' => $set['reps'], 'notes' => $set['notes'] ?? null]], $loggedAt)[0];
        }
        $this->db->prepare(
            'INSERT INTO workouts (user_id, exercise, weight, reps, notes, logged_at, session_id, set_no, planned_percent, planned_weight)
             VALUES (:u, :e, :w, :r, :n, :at, :s, :no, :pp, :pw)'
        )->execute([
            ':u' => $userId, ':e' => $exercise, ':w' => $set['weight'], ':r' => $set['reps'], ':n' => $set['notes'] ?? null, ':at' => $loggedAt ?? date('Y-m-d H:i:s'),
            ':s' => $set['session_id'], ':no' => $set['set_no'], ':pp' => $set['planned_percent'], ':pw' => $set['planned_weight'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The sets of one training session for one person: id, exercise, set_no, weight, reps, notes — in the order they were done.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionSets(int $userId, int $sessionId): array
    {
        if (!$this->hasSessionColumns()) {
            return [];
        }
        $st = $this->db->prepare('SELECT id, exercise, set_no, weight, reps, notes, planned_percent, planned_weight, logged_at FROM workouts WHERE user_id = :u AND session_id = :s ORDER BY id ASC');
        $st->execute([':u' => $userId, ':s' => $sessionId]);

        return $st->fetchAll();
    }

    /** One set of a session (for undoing a tick), or null. @return array<string, mixed>|null */
    public function sessionSet(int $userId, int $id): ?array
    {
        if (!$this->hasSessionColumns()) {
            return null;
        }
        $st = $this->db->prepare('SELECT id, exercise, session_id, set_no FROM workouts WHERE user_id = :u AND id = :id AND session_id IS NOT NULL');
        $st->execute([':u' => $userId, ':id' => $id]);
        $r = $st->fetch();

        return $r === false ? null : $r;
    }

    /**
     * The most recent earlier outing with this exercise, for the Train card's "last time": the last set's weight and reps, the date, and the newest comment (the app's own
     * "From plan" / "logged by …" marks are not comments). Null when there is no earlier set.
     *
     * @return array{weight:?float, reps:?int, date:string, note:?string}|null
     */
    public function lastTime(int $userId, string $exercise, string $beforeDate): ?array
    {
        $st = $this->db->prepare('SELECT weight, reps, notes, logged_at FROM workouts WHERE user_id = :u AND exercise = :e AND logged_at < :d ORDER BY logged_at DESC, id DESC LIMIT 40');
        $st->execute([':u' => $userId, ':e' => $exercise, ':d' => $beforeDate . ' 00:00:00']);
        $rows = $st->fetchAll();
        if ($rows === []) {
            return null;
        }
        $day  = substr((string) $rows[0]['logged_at'], 0, 10);
        $note = null;
        foreach ($rows as $r) {
            $n = trim((string) preg_replace('/\s*·?\s*logged by .*$/u', '', (string) ($r['notes'] ?? '')));
            if ($n !== '' && strcasecmp($n, 'From plan') !== 0) {
                $note = $n;
                break;
            }
        }

        return [
            'weight' => $rows[0]['weight'] !== null ? (float) $rows[0]['weight'] : null,
            'reps'   => $rows[0]['reps'] !== null ? (int) $rows[0]['reps'] : null,
            'date'   => $day, 'note' => $note,
        ];
    }

    /**
     * The heaviest weight ever logged for an exercise (user-scoped), or null if it has
     * never been logged with a weight. Used to detect a new personal best on logging.
     */
    public function previousBestWeight(int $userId, string $exercise): ?float
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(weight) FROM workouts WHERE user_id = :u AND exercise = :e AND weight IS NOT NULL'
        );
        $stmt->execute([':u' => $userId, ':e' => $exercise]);
        $v = $stmt->fetchColumn();

        return ($v === null || $v === false) ? null : (float) $v;
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
     * Returns the user's distinct exercise names, most-recently-trained first.
     * Used to populate the exercise picker on the progression card.
     *
     * @return string[]
     */
    public function distinctExercises(int $userId, ?int $limit = null): array
    {
        $sql = 'SELECT exercise FROM workouts
                WHERE user_id = :user_id
                GROUP BY exercise
                ORDER BY MAX(logged_at) DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
     * Re-labels every set whose exercise name matches one of $fromNames (compared
     * case-insensitively) to $canonical, scoped to the user. Used when merging
     * name variants of the same lift. Returns the number of rows changed.
     *
     * @param array<int, string> $fromNames
     */
    public function renameExercises(int $userId, array $fromNames, string $canonical): int
    {
        $canonical = trim($canonical);
        $lowered   = [];
        foreach ($fromNames as $name) {
            $n = mb_strtolower(trim((string) $name));
            if ($n !== '') {
                $lowered[$n] = true;
            }
        }
        if ($canonical === '' || $lowered === []) {
            return 0;
        }

        $keys         = array_keys($lowered);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->db->prepare(
            "UPDATE workouts SET exercise = ?
             WHERE user_id = ? AND LOWER(exercise) IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$canonical, $userId], $keys));

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
