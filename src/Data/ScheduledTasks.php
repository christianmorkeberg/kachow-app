<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Standing natural-language instructions the user asks the assistant to re-run on a
 * schedule (e.g. "once a day, check my email for invoices and notify me if you find
 * anything") — see bin/scheduled-tasks-cron.php, which feeds `instruction` back into
 * AssistantLoop as if the user had just typed it. next_run_at is stored in UTC;
 * time_of_day/day_of_week are the user's own wall-clock schedule (Europe/Copenhagen).
 */
final class ScheduledTasks
{
    private const LOCAL_TZ = 'Europe/Copenhagen';
    public const FREQUENCIES = ['minutes', 'hourly', 'daily', 'weekdays', 'weekly', 'once'];
    /** Every run is a full model turn, so "every N minutes" has a floor. */
    public const MIN_INTERVAL_MINUTES = 5;
    public const MAX_INTERVAL_MINUTES = 1440;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Creates an active task with its first next_run_at already computed. Returns its id. */
    public function create(
        int $userId,
        int $conversationId,
        string $label,
        string $instruction,
        string $frequency,
        ?string $timeOfDay,
        ?int $dayOfWeek,
        ?int $intervalMinutes = null,
        bool $notifyAlways = false,
        ?DateTimeImmutable $onceAtUtc = null
    ): int {
        $nextRunAtUtc = $frequency === 'once' && $onceAtUtc !== null
            ? $onceAtUtc
            : self::nextRunAtUtc($frequency, $timeOfDay, $dayOfWeek, null, $intervalMinutes);

        $stmt = $this->db->prepare(
            'INSERT INTO scheduled_tasks
                (user_id, conversation_id, label, instruction, frequency, time_of_day, day_of_week,
                 interval_minutes, notify_always, next_run_at)
             VALUES (:u, :conv, :label, :instr, :freq, :tod, :dow, :int, :always, :next)'
        );
        $stmt->execute([
            ':u'     => $userId,
            ':conv'  => $conversationId,
            ':label' => mb_substr(trim($label), 0, 80),
            ':instr' => trim($instruction),
            ':freq'  => $frequency,
            ':tod'   => $timeOfDay,
            ':dow'   => $dayOfWeek,
            ':int'   => $intervalMinutes,
            ':always' => $notifyAlways ? 1 : 0,
            ':next'  => $nextRunAtUtc->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A user's active tasks, soonest-next-run first.
     *
     * @return array<int, array{id:int, conversation_id:int, label:string, instruction:string,
     *   frequency:string, time_of_day:?string, day_of_week:?int, next_run_at:string, last_run_at:?string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, conversation_id, label, instruction, frequency, time_of_day, day_of_week,
                    interval_minutes, notify_always, next_run_at, last_run_at
             FROM scheduled_tasks WHERE user_id = :u AND status = 'active'
             ORDER BY next_run_at ASC, id ASC"
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'              => (int) $r['id'],
                'conversation_id' => (int) $r['conversation_id'],
                'label'           => (string) $r['label'],
                'instruction'     => (string) $r['instruction'],
                'frequency'       => (string) $r['frequency'],
                'time_of_day'     => $r['time_of_day'] !== null ? (string) $r['time_of_day'] : null,
                'day_of_week'     => $r['day_of_week'] !== null ? (int) $r['day_of_week'] : null,
                'interval_minutes' => $r['interval_minutes'] !== null ? (int) $r['interval_minutes'] : null,
                'notify_always'   => (bool) $r['notify_always'],
                'next_run_at'     => (string) $r['next_run_at'],
                'last_run_at'     => $r['last_run_at'] !== null ? (string) $r['last_run_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * The task (any status) that owns this conversation, or null for an ordinary chat.
     *
     * @return array{id:int, label:string, status:string}|null
     */
    public function byConversation(int $userId, int $conversationId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, label, status FROM scheduled_tasks WHERE user_id = :u AND conversation_id = :c LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':c' => $conversationId]);
        $r = $stmt->fetch();

        return $r === false ? null : ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'status' => (string) $r['status']];
    }

    /**
     * Older runs stored the cron's unattended-run rules ("[Scheduled task — …]") at the start of the
     * user message. They are boilerplate for the model, not something the user wrote — drop them for display.
     */
    public static function stripRunPreamble(string $content): string
    {
        return ltrim((string) preg_replace('/^\[Scheduled task\b.*?\]\s*/su', '', $content, 1));
    }

    /** How many active tasks a user currently has (for the schedule_task cap). */
    public function countActive(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM scheduled_tasks WHERE user_id = :u AND status = 'active'"
        );
        $stmt->execute([':u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Cancels a user's active task. Returns true if one was cancelled. */
    public function cancel(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET status = 'cancelled' WHERE id = :id AND user_id = :u AND status = 'active'"
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Marks a task as having a result worth seeing — called by notify_me every time it
     * fires, regardless of whether the push itself actually reached a device, so a missed
     * push still surfaces in-app (see unseenForUser/clearUnseenByConversation).
     */
    public function markResult(int $id, string $title): void
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_tasks SET has_unseen_result = 1, last_result_title = :t WHERE id = :id'
        );
        $stmt->execute([':t' => mb_substr(trim($title), 0, 160), ':id' => $id]);
    }

    /**
     * A user's tasks with an unseen result, most recently run first — for the startup
     * "here's something to see" banner.
     *
     * @return array<int, array{id:int, conversation_id:int, label:string, last_result_title:?string}>
     */
    public function unseenForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, conversation_id, label, last_result_title FROM scheduled_tasks
             WHERE user_id = :u AND has_unseen_result = 1
             ORDER BY last_run_at DESC, id DESC"
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'                => (int) $r['id'],
                'conversation_id'   => (int) $r['conversation_id'],
                'label'             => (string) $r['label'],
                'last_result_title' => $r['last_result_title'] !== null ? (string) $r['last_result_title'] : null,
            ];
        }

        return $out;
    }

    /** Clears the unseen flag for whichever task owns this conversation, if any. */
    public function clearUnseenByConversation(int $userId, int $conversationId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE scheduled_tasks SET has_unseen_result = 0 WHERE user_id = :u AND conversation_id = :c'
        );
        $stmt->execute([':u' => $userId, ':c' => $conversationId]);
    }

    /**
     * Active tasks whose time has arrived (UTC), oldest first.
     *
     * @return array<int, array{id:int, user_id:int, conversation_id:int, label:string,
     *   instruction:string, frequency:string, time_of_day:?string, day_of_week:?int, next_run_at:string}>
     */
    public function due(int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $stmt  = $this->db->query(
            "SELECT id, user_id, conversation_id, label, instruction, frequency, time_of_day, day_of_week,
                    interval_minutes, notify_always, next_run_at
             FROM scheduled_tasks
             WHERE status = 'active' AND next_run_at <= UTC_TIMESTAMP()
             ORDER BY next_run_at ASC, id ASC
             LIMIT " . $limit
        );

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'              => (int) $r['id'],
                'user_id'         => (int) $r['user_id'],
                'conversation_id' => (int) $r['conversation_id'],
                'label'           => (string) $r['label'],
                'instruction'     => (string) $r['instruction'],
                'frequency'       => (string) $r['frequency'],
                'time_of_day'     => $r['time_of_day'] !== null ? (string) $r['time_of_day'] : null,
                'day_of_week'     => $r['day_of_week'] !== null ? (int) $r['day_of_week'] : null,
                'interval_minutes' => $r['interval_minutes'] !== null ? (int) $r['interval_minutes'] : null,
                'notify_always'   => (bool) $r['notify_always'],
                'next_run_at'     => (string) $r['next_run_at'],
            ];
        }

        return $out;
    }

    /** Claims a due one-shot task and finishes it in the same step. True only for the call that won it. */
    public function claimOnce(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET status = 'done', last_run_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active' AND next_run_at <= UTC_TIMESTAMP()"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically claims a due task AND advances it to its next occurrence in one step —
     * so a slow or failing run can't double-fire, and a missed run doesn't stall the
     * schedule (it just catches up a step at a time). Returns true only if THIS call
     * claimed it (an overlapping cron run's same update affects 0 rows).
     */
    public function claimAndReschedule(int $id, string $nextRunAtUtc): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET next_run_at = :next, last_run_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = 'active' AND next_run_at <= UTC_TIMESTAMP()"
        );
        $stmt->execute([':next' => $nextRunAtUtc, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The next occurrence (UTC) strictly after $after (default now), from a wall-clock
     * schedule in Europe/Copenhagen — so DST shifts never drift the local time of day.
     * $timeOfDay is "HH:MM" (defaults to "08:00" for daily/weekly if omitted); $dayOfWeek
     * is ISO 1=Mon..7=Sun (required for weekly, ignored otherwise). 'weekdays' is daily minus
     * Saturday and Sunday; 'minutes' is $after + $intervalMinutes (clamped to the allowed range).
     */
    public static function nextRunAtUtc(
        string $frequency,
        ?string $timeOfDay,
        ?int $dayOfWeek,
        ?DateTimeImmutable $after = null,
        ?int $intervalMinutes = null
    ): DateTimeImmutable {
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $utc   = new DateTimeZone('UTC');
        $after = ($after ?? new DateTimeImmutable('now', $utc))->setTimezone($tz);

        if ($frequency === 'minutes') {
            $n = max(self::MIN_INTERVAL_MINUTES, min(self::MAX_INTERVAL_MINUTES, $intervalMinutes ?? 60));

            return $after->modify('+' . $n . ' minutes')->setTimezone($utc);
        }

        if ($frequency === 'hourly') {
            return $after->modify('+1 hour')->setTimezone($utc);
        }

        [$h, $m] = self::parseTimeOfDay($timeOfDay);
        $candidate = $after->setTime($h, $m, 0);
        if ($candidate <= $after) {
            $candidate = $candidate->modify('+1 day');
        }

        if ($frequency === 'weekdays') {
            while ((int) $candidate->format('N') >= 6) {
                $candidate = $candidate->modify('+1 day');
            }
        }

        if ($frequency === 'weekly') {
            $target = $dayOfWeek ?? (int) $candidate->format('N');
            while ((int) $candidate->format('N') !== $target) {
                $candidate = $candidate->modify('+1 day');
            }
        }

        return $candidate->setTimezone($utc);
    }

    /** @return array{0:int, 1:int} [hour, minute], defaulting to 08:00 on anything unparsable. */
    private static function parseTimeOfDay(?string $timeOfDay): array
    {
        $s = trim((string) $timeOfDay);
        if ($s !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $s, $m) === 1) {
            $h = max(0, min(23, (int) $m[1]));
            $i = max(0, min(59, (int) $m[2]));

            return [$h, $i];
        }

        return [8, 0];
    }
}
