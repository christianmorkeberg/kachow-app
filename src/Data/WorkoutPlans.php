<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use PDO;

/**
 * Data-access for dynamic workout planning: `workout_plans` (a session on a date)
 * and `workout_plan_items` (its exercises, with optional structured targets).
 *
 * Distinct from Workouts (logged sets = what you DID); this is what you PLAN to do.
 * When an item with targets is ticked done, it also logs the sets to history — the
 * one place these two layers meet, via an injected Workouts. Ticking logs at most
 * once (the `logged` flag); unticking leaves the logged sets in place.
 *
 * `cardFor*()` return the render structure used both as a tool `_render` payload
 * and by the workout-plan endpoint, so the chat widget and the API agree.
 */
final class WorkoutPlans
{
    private PDO $db;
    private ?Workouts $workouts;

    public function __construct(?PDO $db = null, ?Workouts $workouts = null)
    {
        $this->db       = $db ?? Database::get();
        $this->workouts = $workouts;
    }

    // ---- plans -------------------------------------------------------------

    /** One plan per user per date; creates it if missing. Optionally sets a title. */
    public function ensurePlanForDate(int $userId, string $date, ?string $title = null): int
    {
        $existing = $this->planIdForDate($userId, $date);
        if ($existing !== null) {
            if ($title !== null && $title !== '') {
                $this->db->prepare('UPDATE workout_plans SET title = :t WHERE id = :id')
                    ->execute([':t' => $title, ':id' => $existing]);
            }
            return $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO workout_plans (user_id, plan_date, title) VALUES (:u, :d, :t)'
        );
        $stmt->execute([':u' => $userId, ':d' => $date, ':t' => $title !== '' ? $title : null]);

        return (int) $this->db->lastInsertId();
    }

    public function planIdForDate(int $userId, string $date): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM workout_plans WHERE user_id = :u AND plan_date = :d LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':d' => $date]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function planById(int $userId, int $planId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM workout_plans WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $planId, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function deletePlan(int $userId, int $planId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM workout_plans WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $planId, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    // ---- items -------------------------------------------------------------

    public function addItem(
        int $planId,
        string $exercise,
        ?int $sets = null,
        ?int $reps = null,
        ?float $weight = null,
        ?string $note = null
    ): int {
        $pos = (int) $this->db->query('SELECT COALESCE(MAX(position),0)+1 FROM workout_plan_items WHERE plan_id = ' . (int) $planId)->fetchColumn();

        $stmt = $this->db->prepare(
            'INSERT INTO workout_plan_items (plan_id, exercise, target_sets, target_reps, target_weight, note, position)
             VALUES (:p, :e, :s, :r, :w, :n, :pos)'
        );
        $stmt->execute([
            ':p'   => $planId,
            ':e'   => $exercise,
            ':s'   => $sets,
            ':r'   => $reps,
            ':w'   => $weight,
            ':n'   => $note !== '' ? $note : null,
            ':pos' => $pos,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<int, array<string,mixed>> */
    public function itemsForPlan(int $planId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM workout_plan_items WHERE plan_id = :p ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':p' => $planId]);

        return $stmt->fetchAll();
    }

    /** An item joined to its plan (for user-scoped ops), or null. */
    public function findItem(int $userId, int $itemId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, p.user_id, p.plan_date
             FROM workout_plan_items i
             JOIN workout_plans p ON p.id = i.plan_id
             WHERE i.id = :id AND p.user_id = :u'
        );
        $stmt->execute([':id' => $itemId, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function removeItem(int $userId, int $itemId): bool
    {
        $item = $this->findItem($userId, $itemId);
        if ($item === null) {
            return false;
        }
        $this->db->prepare('DELETE FROM workout_plan_items WHERE id = :id')->execute([':id' => $itemId]);

        return true;
    }

    /**
     * Ticks/unticks an item. On first tick of an item with structured targets, also
     * logs the sets to workout history (once). Returns a small status array.
     *
     * @return array{done:bool, logged:bool, exercise:string}|array{error:string}
     */
    public function check(int $userId, int $itemId, bool $done): array
    {
        $item = $this->findItem($userId, $itemId);
        if ($item === null) {
            return ['error' => 'No such planned exercise.'];
        }

        $this->db->prepare('UPDATE workout_plan_items SET done = :d, done_at = :da WHERE id = :id')
            ->execute([':d' => $done ? 1 : 0, ':da' => $done ? date('Y-m-d H:i:s') : null, ':id' => $itemId]);

        $loggedNow = false;
        if ($done && (int) $item['logged'] === 0 && (int) ($item['target_sets'] ?? 0) > 0 && $this->workouts !== null) {
            $sets = array_fill(0, (int) $item['target_sets'], [
                'weight' => $item['target_weight'] !== null ? (float) $item['target_weight'] : null,
                'reps'   => $item['target_reps'] !== null ? (int) $item['target_reps'] : null,
                'notes'  => 'From plan',
            ]);
            $loggedAt = (string) $item['plan_date'] . ' ' . date('H:i:s');
            $this->workouts->logSets($userId, (string) $item['exercise'], $sets, $loggedAt);
            $this->db->prepare('UPDATE workout_plan_items SET logged = 1 WHERE id = :id')->execute([':id' => $itemId]);
            $loggedNow = true;
        }

        return ['done' => $done, 'logged' => $loggedNow, 'exercise' => (string) $item['exercise']];
    }

    // ---- render cards ------------------------------------------------------

    /** Card for a single day (used by get/create-plan tools + endpoint). */
    public function cardForDate(int $userId, string $date): array
    {
        return [
            'kind'  => 'workout_plan',
            'title' => 'Workout — ' . self::prettyDate($date),
            'days'  => [$this->dayCard($userId, $date)],
        ];
    }

    /** Card for the week (Mon–Sun) containing $refDate. */
    public function cardForWeek(int $userId, string $refDate): array
    {
        $ref     = new DateTimeImmutable($refDate);
        $monday  = $ref->modify('monday this week');
        $days    = [];
        $remaining = 0;
        for ($i = 0; $i < 7; $i++) {
            $d   = $monday->modify("+{$i} days")->format('Y-m-d');
            $day = $this->dayCard($userId, $d);
            if ($day['plan_id'] !== null) {
                $days[] = $day;
                foreach ($day['items'] as $it) {
                    if (!$it['done']) {
                        $remaining++;
                    }
                }
            }
        }

        return [
            'kind'      => 'workout_plan',
            'title'     => 'This week',
            'days'      => $days,
            'remaining' => $remaining,
        ];
    }

    /**
     * @return array{date:string, weekday:string, plan_id:int|null, plan_title:?string, items:array<int,array<string,mixed>>}
     */
    public function dayCard(int $userId, string $date): array
    {
        $planId = $this->planIdForDate($userId, $date);
        $items  = [];
        $title  = null;
        if ($planId !== null) {
            $plan  = $this->planById($userId, $planId);
            $title = $plan['title'] ?? null;
            foreach ($this->itemsForPlan($planId) as $row) {
                $items[] = [
                    'id'    => (int) $row['id'],
                    'label' => self::itemLabel($row),
                    'done'  => (bool) $row['done'],
                ];
            }
        }

        return [
            'date'       => $date,
            'weekday'    => (new DateTimeImmutable($date))->format('l'),
            'plan_id'    => $planId,
            'plan_title' => $title,
            'items'      => $items,
        ];
    }

    /** "Squat — 3×5 @ 100 kg" / "Run — 5k easy" (note) / "Pull-ups — 3 sets". */
    private static function itemLabel(array $row): string
    {
        $label = (string) $row['exercise'];
        $sets  = $row['target_sets'] !== null ? (int) $row['target_sets'] : null;
        $reps  = $row['target_reps'] !== null ? (int) $row['target_reps'] : null;

        $target = '';
        if ($sets !== null && $reps !== null) {
            $target = "{$sets}×{$reps}";
        } elseif ($sets !== null) {
            $target = "{$sets} sets";
        } elseif ($reps !== null) {
            $target = "{$reps} reps";
        }
        if ($row['target_weight'] !== null) {
            $w = rtrim(rtrim(number_format((float) $row['target_weight'], 2, '.', ''), '0'), '.');
            $target .= ($target !== '' ? ' @ ' : '') . $w . ' kg';
        }
        if ($target !== '') {
            $label .= ' — ' . $target;
        }
        if (!empty($row['note'])) {
            $label .= ($target !== '' ? ' (' . $row['note'] . ')' : ' — ' . $row['note']);
        }

        return $label;
    }

    private static function prettyDate(string $date): string
    {
        return (new DateTimeImmutable($date))->format('D j M');
    }
}
