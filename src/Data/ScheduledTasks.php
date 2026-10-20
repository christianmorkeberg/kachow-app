<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\RoutineGate;
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
            'UPDATE scheduled_tasks SET has_unseen_result = 1, last_run_notified = 1, last_result_title = :t WHERE id = :id'
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
                    interval_minutes, notify_always, next_run_at, run_mode, steps
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
                'run_mode'        => (string) $r['run_mode'],
                'steps'           => Routines::decodeList($r['steps']),
            ];
        }

        return $out;
    }

    /** Claims a due one-shot task and finishes it in the same step. True only for the call that won it. */
    public function claimOnce(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE scheduled_tasks SET status = 'done', last_run_at = UTC_TIMESTAMP(), last_run_notified = 0
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
            "UPDATE scheduled_tasks SET next_run_at = :next, last_run_at = UTC_TIMESTAMP(), last_run_notified = 0
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

    /**
     * What a run came to, for the card: ok/error and the run's closing words (the assistant's final
     * reply, which for a quiet check is usually "nothing to report"). Called by the cron after each run.
     */
    public function recordRun(int $id, bool $ok, string $summary): void
    {
        $stmt = $this->db->prepare('UPDATE scheduled_tasks SET last_run_status = :s, last_summary = :t WHERE id = :id');
        $stmt->execute([':s' => $ok ? 'ok' : 'error', ':t' => mb_substr(trim($summary), 0, 1500), ':id' => $id]);
    }

    /**
     * One task of this user, any status, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare($this->cardSelect() . ' WHERE user_id = :u AND id = :id');
        $stmt->execute([':u' => $userId, ':id' => $id]);
        $r = $stmt->fetch();

        return $r === false ? null : self::cardRow($r);
    }

    /**
     * The tasks the card shows: active ones soonest first, then paused ones, then one-offs that already
     * ran (most recent 10). Cancelled tasks are gone.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCard(int $userId): array
    {
        $stmt = $this->db->prepare($this->cardSelect() . " WHERE user_id = :u AND status IN ('draft','active','paused','done')");
        $stmt->execute([':u' => $userId]);
        $rows = array_map([self::class, 'cardRow'], $stmt->fetchAll());

        $rank = ['draft' => 0, 'active' => 1, 'paused' => 2, 'done' => 3];
        usort($rows, static function (array $a, array $b) use ($rank): int {
            return [$rank[$a['status']], $a['status'] === 'done' ? '' : $a['next_run_at'], $a['status'] === 'done' ? $b['last_run_at'] : '', $a['id']]
                <=> [$rank[$b['status']], $b['status'] === 'done' ? '' : $b['next_run_at'], $b['status'] === 'done' ? $a['last_run_at'] : '', $b['id']];
        });
        $done = 0;

        return array_values(array_filter($rows, static function (array $r) use (&$done): bool {
            return $r['status'] !== 'done' || ++$done <= 10;
        }));
    }

    /**
     * The scheduled-tasks / routines card for a user (place names resolved for "Leaving Home").
     *
     * @return array<string, mixed>
     */
    public function cardForUser(int $userId, ?Places $places = null, ?array $system = null): array
    {
        $names = [];
        if ($places !== null) {
            foreach ($places->list($userId) as $p) {
                $names[(int) $p['id']] = (string) $p['name'];
            }
        }

        $requests = [];
        try {
            $requests = (new Routines($this->db))->pendingRequests($userId);
        } catch (\Throwable) {
            // not migrated yet: no questions
        }

        return self::card($this->listForCard($userId), $system, $names, $requests);
    }

    /**
     * Pauses (active → paused) or resumes (paused → active) a task. Resuming picks the next slot from
     * NOW, so the slots missed while paused are not replayed. A paused one-off can only resume while its
     * time is still ahead. Returns null on success, else why not.
     */
    public function setActive(int $userId, int $id, bool $active): ?string
    {
        $t = $this->find($userId, $id);
        if ($t === null) {
            return 'No such scheduled task.';
        }
        if ($t['status'] === 'done' || $t['status'] === 'cancelled') {
            return 'That task has already finished.';
        }
        if ($active) {
            if ($t['status'] === 'active') {
                return null;
            }
            if ($t['frequency'] === 'event') {
                $next = new DateTimeImmutable(Routines::NEVER, new DateTimeZone('UTC'));   // runs on request, never by the clock
            } elseif ($t['frequency'] === 'once') {
                $next = new DateTimeImmutable($t['next_run_at'], new DateTimeZone('UTC'));
                if ($next <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    return 'That one-off time has passed, so it cannot be resumed — schedule it again.';
                }
            } else {
                $next = self::nextRunAtUtc($t['frequency'], $t['time_of_day'], $t['day_of_week'], null, $t['interval_minutes']);
            }
            $this->db->prepare("UPDATE scheduled_tasks SET status = 'active', next_run_at = :n WHERE id = :id AND user_id = :u")
                ->execute([':n' => $next->format('Y-m-d H:i:s'), ':id' => $id, ':u' => $userId]);

            return null;
        }
        $this->db->prepare("UPDATE scheduled_tasks SET status = 'paused' WHERE id = :id AND user_id = :u AND status = 'active'")
            ->execute([':id' => $id, ':u' => $userId]);

        return null;
    }

    /** "Every weekday at 08:00", "Every 30 minutes", "Sundays at 19:00" … in English (the card is shown as is). */
    public static function describeSchedule(string $frequency, ?string $timeOfDay, ?int $dayOfWeek, ?int $intervalMinutes): string
    {
        [$h, $m] = self::parseTimeOfDay($timeOfDay);
        $at      = sprintf('%02d:%02d', $h, $m);
        $days    = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        return match ($frequency) {
            'minutes'  => ($intervalMinutes ?? 60) % 60 === 0 && ($intervalMinutes ?? 60) >= 60
                ? 'Every ' . (($intervalMinutes ?? 60) === 60 ? 'hour' : (($intervalMinutes ?? 60) / 60) . ' hours')
                : 'Every ' . ($intervalMinutes ?? 60) . ' minutes',
            'hourly'   => 'Every hour',
            'daily'    => 'Every day at ' . $at,
            'weekdays' => 'Every weekday at ' . $at,
            'weekly'   => 'Every ' . ($days[$dayOfWeek ?? 1] ?? 'week') . ' at ' . $at,
            'once'     => 'Once',
            'event'    => 'When triggered',
            default    => $frequency,
        };
    }

    /**
     * The card (kind: scheduled_tasks) for these tasks (from listForCard) and, for the developer, the
     * system jobs (from SystemTasks::listForCard).
     *
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>>|null $system null for a non-developer (no system section)
     * @return array<string, mixed>
     */
    public static function card(array $tasks, ?array $system = null, array $placeNames = [], array $requests = []): array
    {
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $local = static function (?string $utc, string $fmt) use ($tz): ?string {
            return $utc === null ? null : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($fmt);
        };
        $items = [];
        foreach ($tasks as $t) {
            $isRoutine = in_array($t['trigger_type'] ?? 'time', ['phrase', 'place', 'sensor'], true);
            $items[] = [
                'id'          => $t['id'],
                'label'       => $t['label'],
                'instruction' => mb_substr((string) $t['instruction'], 0, 400),
                'status'      => $t['status'],
                'schedule'    => $isRoutine
                    ? Routines::describeTrigger($t, $placeNames)
                    : self::describeSchedule($t['frequency'], $t['time_of_day'], $t['day_of_week'], $t['interval_minutes']),
                'routine'     => $isRoutine,
                'trigger_type' => $t['trigger_type'] ?? 'time',
                'phrases'     => $t['phrases'] ?? [],
                'conditions_text' => RoutineGate::describe($t['conditions'] ?? []),
                'run_mode'    => $t['run_mode'] ?? 'auto',
                'run_trigger' => $t['run_trigger'] ?? null,
                'trigger'     => $t['trigger'] ?? [],
                'conditions'  => $t['conditions'] ?? [],
                'steps'       => count($t['steps'] ?? []),
                'can_undo'    => $t['can_undo'] ?? false,
                'once'        => $t['frequency'] === 'once',
                'tell_always' => $t['notify_always'],
                'next_run'    => $t['status'] === 'active' && $t['frequency'] !== 'event' ? $local($t['next_run_at'], 'D j M, H:i') : null,
                'last_run'    => $local($t['last_run_at'], 'D j M, H:i'),
                'last_status' => $t['last_run_status'],
                'notified'    => $t['last_run_notified'],
                'last_title'  => $t['last_result_title'],
                'last_summary' => $t['last_summary'],
                'unseen'      => $t['has_unseen_result'],
                'conversation_id' => $t['conversation_id'],
            ];
        }
        $card = [
            'kind'  => 'scheduled_tasks',
            'title' => 'Scheduled tasks',
            'items' => $items,
            // "Ask first" routines waiting for a yes / no (see Routines::pendingRequests)
            'places'   => array_values(array_map(static fn ($id, $name): array => ['id' => (int) $id, 'name' => (string) $name], array_keys($placeNames), $placeNames)),
            'requests' => array_map(static fn (array $q): array => [
                'id' => $q['id'], 'task_id' => $q['task_id'], 'label' => $q['label'], 'trigger_text' => $q['trigger_text'],
                'at' => $local($q['created_at'], 'H:i'),
            ], $requests),
        ];
        if ($system !== null) {
            $card['system'] = $system;
        }

        return $card;
    }

    private function cardSelect(): string
    {
        return 'SELECT id, user_id, conversation_id, label, instruction, frequency, time_of_day, day_of_week, interval_minutes,
                       notify_always, status, next_run_at, last_run_at, has_unseen_result, last_result_title,
                       last_summary, last_run_status, last_run_notified,
                       trigger_type, phrases, trigger_config, conditions, run_mode, run_trigger, steps, undo_steps, undo_at
                FROM scheduled_tasks';
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function cardRow(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'conversation_id'  => (int) $r['conversation_id'],
            'label'            => (string) $r['label'],
            'instruction'      => (string) $r['instruction'],
            'frequency'        => (string) $r['frequency'],
            'time_of_day'      => $r['time_of_day'] !== null ? (string) $r['time_of_day'] : null,
            'day_of_week'      => $r['day_of_week'] !== null ? (int) $r['day_of_week'] : null,
            'interval_minutes' => $r['interval_minutes'] !== null ? (int) $r['interval_minutes'] : null,
            'notify_always'    => (bool) $r['notify_always'],
            'status'           => (string) $r['status'],
            'next_run_at'      => (string) $r['next_run_at'],
            'last_run_at'      => $r['last_run_at'] !== null ? (string) $r['last_run_at'] : null,
            'has_unseen_result' => (bool) $r['has_unseen_result'],
            'last_result_title' => $r['last_result_title'] !== null ? (string) $r['last_result_title'] : null,
            'last_summary'     => $r['last_summary'] !== null ? (string) $r['last_summary'] : null,
            'last_run_status'  => $r['last_run_status'] !== null ? (string) $r['last_run_status'] : null,
            'last_run_notified' => (bool) $r['last_run_notified'],
            // routines (phrase / place triggered tasks); a plain time task has trigger_type 'time'
            'trigger_type'     => (string) ($r['trigger_type'] ?? 'time'),
            'phrases'          => array_values(array_filter(array_map('trim', explode("\n", (string) ($r['phrases'] ?? ''))), static fn (string $x): bool => $x !== '')),
            'trigger'          => (array) json_decode((string) ($r['trigger_config'] ?? ''), true),
            'conditions'       => (array) json_decode((string) ($r['conditions'] ?? ''), true),
            'run_mode'         => (string) ($r['run_mode'] ?? 'auto'),
            'run_trigger'      => $r['run_trigger'] ?? null,
            'steps'            => Routines::decodeList($r['steps'] ?? null) ?? [],
            'can_undo'         => ($r['undo_steps'] ?? null) !== null && ($r['undo_at'] ?? null) !== null && strtotime((string) $r['undo_at'] . ' UTC') > time() - 86400,
        ];
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
