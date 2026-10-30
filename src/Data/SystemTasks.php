<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The built-in cron jobs as manageable tasks. The jobs themselves stay ordinary scripts in bin/ (their
 * logic is untouched); this adds what a scheduled task has: a status (pause/resume), a schedule, who
 * starts it, and what the last run came to. One row per catalogue entry, created on first use.
 *
 * Who starts a job is its `mode`: 'cpanel' (the server's own cron entry, as before — the schedule here is
 * then only informational) or 'dispatcher' (bin/scheduled-tasks-cron.php, which runs every minute, starts
 * it when it is due). A script asks gate() at its start, so a paused job, or one the dispatcher owns, exits
 * quietly when the other trigger fires.
 */
final class SystemTasks
{
    /**
     * key => label, what it does, the script in bin/, and the schedule the cPanel entry has today
     * (which is also the default for the dispatcher).
     *
     * `paused` = the row is created paused (an opt-in job).
     *
     * @var array<string, array{label:string, description:string, script:string, frequency:string, time_of_day:?string, day_of_week:?int, interval_minutes:?int, paused?:bool}>
     */
    public const CATALOG = [
        'notifications' => [
            'label'       => 'Notifications',
            'description' => 'Decides who needs a push: clock-out nudge, weekly summary, work-log nudge, period and moms reminders, '
                . 'working-from-home prompt, tracking stopped, hours to fill in.',
            'script' => 'notify-cron.php', 'frequency' => 'minutes', 'time_of_day' => null, 'day_of_week' => null, 'interval_minutes' => 30,
        ],
        'reminders' => [
            'label'       => 'Reminders',
            'description' => 'Delivers the one-off reminders that are due as a push.',
            'script' => 'reminders-cron.php', 'frequency' => 'minutes', 'time_of_day' => null, 'day_of_week' => null, 'interval_minutes' => 5,
        ],
        'quick_actions' => [
            'label'       => 'Conversation starters',
            'description' => 'Writes fresh starter suggestions for the empty chat screen.',
            'script' => 'quick-actions-cron.php', 'frequency' => 'daily', 'time_of_day' => '04:30', 'day_of_week' => null, 'interval_minutes' => null,
        ],
        'home_sync' => [
            'label'       => 'Home device list',
            'description' => 'Refreshes the saved name-to-id list of lights and devices through the home agent.',
            'script' => 'home-sync-cron.php', 'frequency' => 'daily', 'time_of_day' => '04:15', 'day_of_week' => null, 'interval_minutes' => null,
        ],
        'memory_cleanup' => [
            'label'       => 'Memory cleanup',
            'description' => 'Condenses duplicate memories (only for users who opted in).',
            'script' => 'memory-cleanup-cron.php', 'frequency' => 'weekly', 'time_of_day' => '04:10', 'day_of_week' => 7, 'interval_minutes' => null,
        ],
        'location_freeze' => [
            'label'       => 'Location day freeze',
            'description' => 'Freezes each finished day\'s stays and trips so they outlive the 60-day raw-point retention.',
            'script' => 'location-freeze-cron.php', 'frequency' => 'daily', 'time_of_day' => '04:40', 'day_of_week' => null, 'interval_minutes' => null,
        ],
        'home_state' => [
            'label'       => 'Home state cache',
            'description' => 'Every 5 minutes reads the lights and all radiator valves through the home agent and keeps the result, so the assistant, the Home card and the kiosk answer instantly '
                . 'instead of waiting in the Pico\'s one-at-a-time queue. Needs a cPanel entry every 5 minutes, or the dispatcher.',
            'script' => 'home-state-cron.php', 'frequency' => 'minutes', 'time_of_day' => null, 'day_of_week' => null, 'interval_minutes' => 5,
        ],
        'thermostat_probe' => [
            'label'       => 'Thermostat probe',
            'description' => 'Experiment: reads every radiator thermostat once a minute through the home agent and logs what it says, to find out how often '
                . 'the valves really report (can an open window be detected?). Off until switched on; needs a cPanel entry every minute; keeps 14 days.',
            'script' => 'thermostat-probe-cron.php', 'frequency' => 'minutes', 'time_of_day' => null, 'day_of_week' => null, 'interval_minutes' => 1, 'paused' => true,
        ],
    ];

    public const FREQUENCIES = ['minutes', 'daily', 'weekdays', 'weekly'];
    public const MODES       = ['cpanel', 'dispatcher'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Creates the rows that are missing (a new catalogue entry, or the first ever call). */
    public function ensureRows(): void
    {
        $have = array_column($this->db->query('SELECT task_key FROM system_tasks')->fetchAll(), 'task_key');
        foreach (self::CATALOG as $key => $c) {
            if (in_array($key, $have, true)) {
                continue;
            }
            $this->db->prepare(
                'INSERT INTO system_tasks (task_key, status, frequency, time_of_day, day_of_week, interval_minutes)
                 VALUES (:k, :s, :f, :t, :d, :i)'
            )->execute([':k' => $key, ':s' => !empty($c['paused']) ? 'paused' : 'active', ':f' => $c['frequency'], ':t' => $c['time_of_day'], ':d' => $c['day_of_week'], ':i' => $c['interval_minutes']]);
        }
    }

    /**
     * Every system task with its catalogue text and state, in catalogue order.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $this->ensureRows();
        $rows = [];
        foreach ($this->db->query('SELECT * FROM system_tasks')->fetchAll() as $r) {
            $rows[(string) $r['task_key']] = $r;
        }
        $out = [];
        foreach (self::CATALOG as $key => $c) {
            if (isset($rows[$key])) {
                $out[] = self::row($key, $rows[$key]);
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        foreach ($this->listAll() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        return null;
    }

    /** Resolves what the user typed ("notifications", "location freeze") to a catalogue key, or null. */
    public static function resolveKey(string $text): ?string
    {
        $norm = static fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? '';
        $want = $norm($text);
        if ($want === '') {
            return null;
        }
        foreach (self::CATALOG as $key => $c) {
            if ($want === $norm($key) || $want === $norm($c['label'])) {
                return $key;
            }
        }
        foreach (self::CATALOG as $key => $c) {
            if (str_contains($norm($c['label']), $want) || str_contains($norm($key), $want)) {
                return $key;
            }
        }

        return null;
    }

    /** Pauses or resumes a job. Returns false for an unknown key. */
    public function setActive(string $key, bool $active): bool
    {
        $t = $this->find($key);
        if ($t === null) {
            return false;
        }
        $this->db->prepare('UPDATE system_tasks SET status = :s WHERE task_key = :k')->execute([':s' => $active ? 'active' : 'paused', ':k' => $key]);
        if ($active && $t['mode'] === 'dispatcher') {
            $this->scheduleNext($key);   // from now — the slots missed while paused are not replayed
        }

        return true;
    }

    /**
     * Changes a job's schedule and/or who starts it. Returns null on success, else why not.
     */
    public function update(string $key, ?string $frequency = null, ?string $timeOfDay = null, ?int $dayOfWeek = null, ?int $intervalMinutes = null, ?string $mode = null): ?string
    {
        $t = $this->find($key);
        if ($t === null) {
            return 'Unknown system task.';
        }
        if ($frequency !== null && !in_array($frequency, self::FREQUENCIES, true)) {
            return 'frequency must be one of: ' . implode(', ', self::FREQUENCIES) . '.';
        }
        if ($mode !== null && !in_array($mode, self::MODES, true)) {
            return 'mode must be cpanel or dispatcher.';
        }
        if ($timeOfDay !== null && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $timeOfDay) !== 1) {
            return 'time_of_day must be HH:MM.';
        }
        if ($dayOfWeek !== null && ($dayOfWeek < 1 || $dayOfWeek > 7)) {
            return 'day_of_week must be 1 (Monday) to 7 (Sunday).';
        }
        if ($intervalMinutes !== null && ($intervalMinutes < ScheduledTasks::MIN_INTERVAL_MINUTES || $intervalMinutes > ScheduledTasks::MAX_INTERVAL_MINUTES)) {
            return 'interval_minutes must be ' . ScheduledTasks::MIN_INTERVAL_MINUTES . ' to ' . ScheduledTasks::MAX_INTERVAL_MINUTES . '.';
        }
        $frequency ??= $t['frequency'];
        if ($frequency === 'minutes' && ($intervalMinutes ?? $t['interval_minutes']) === null) {
            return 'An every-N-minutes schedule needs interval_minutes.';
        }
        if ($frequency === 'weekly' && ($dayOfWeek ?? $t['day_of_week']) === null) {
            return 'A weekly schedule needs day_of_week.';
        }

        $this->db->prepare(
            'UPDATE system_tasks SET frequency = :f, time_of_day = :t, day_of_week = :d, interval_minutes = :i, mode = :m WHERE task_key = :k'
        )->execute([
            ':f' => $frequency,
            ':t' => $frequency === 'minutes' ? null : ($timeOfDay ?? $t['time_of_day'] ?? '04:00'),
            ':d' => $frequency === 'weekly' ? ($dayOfWeek ?? $t['day_of_week']) : null,
            ':i' => $frequency === 'minutes' ? ($intervalMinutes ?? $t['interval_minutes']) : null,
            ':m' => $mode ?? $t['mode'],
            ':k' => $key,
        ]);
        if (($mode ?? $t['mode']) === 'dispatcher') {
            $this->scheduleNext($key);
        } else {
            $this->db->prepare('UPDATE system_tasks SET next_run_at = NULL WHERE task_key = :k')->execute([':k' => $key]);
        }

        return null;
    }

    /**
     * Asked by a script at its start. 'run' = go ahead; 'paused' = the job is paused; 'elsewhere' = the
     * dispatcher owns it and this is the cPanel entry firing. $dispatched is true for the dispatcher's own start.
     */
    public function gate(string $key, bool $dispatched): string
    {
        $this->ensureRows();
        $stmt = $this->db->prepare('SELECT status, mode FROM system_tasks WHERE task_key = :k');
        $stmt->execute([':k' => $key]);
        $r = $stmt->fetch();
        if ($r === false) {
            return 'run';   // not a catalogued job
        }
        if ($r['status'] === 'paused') {
            return 'paused';
        }
        if ($r['mode'] === 'dispatcher' && !$dispatched) {
            return 'elsewhere';
        }

        return 'run';
    }

    /** What a run came to. $output is the tail of what the script printed. */
    public function recordRun(string $key, string $status, int $durationMs, string $output): void
    {
        if (!in_array($status, ['ok', 'error', 'skipped'], true)) {
            return;
        }
        $output = trim($output);
        $this->db->prepare(
            'UPDATE system_tasks SET last_run_at = :at, last_status = :s, last_duration_ms = :ms, last_output = :o WHERE task_key = :k'
        )->execute([
            ':at' => gmdate('Y-m-d H:i:s'), ':s' => $status, ':ms' => max(0, $durationMs),
            ':o'  => $output === '' ? null : (mb_strlen($output) > 4000 ? '…' . mb_substr($output, -3999) : $output),
            ':k'  => $key,
        ]);
    }

    /**
     * Dispatcher-owned jobs that are due now.
     *
     * @return list<array<string, mixed>>
     */
    public function due(): array
    {
        $this->ensureRows();
        $keys = array_column(
            $this->db->query("SELECT task_key FROM system_tasks WHERE mode = 'dispatcher' AND status = 'active' AND next_run_at IS NOT NULL AND next_run_at <= UTC_TIMESTAMP()")->fetchAll(),
            'task_key'
        );

        return array_values(array_filter($this->listAll(), static fn (array $t): bool => in_array($t['key'], $keys, true)));
    }

    /** Claims a due job and moves it to its next slot in one step; true only for the call that won it. */
    public function claim(string $key, string $nextRunAtUtc): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE system_tasks SET next_run_at = :n WHERE task_key = :k AND mode = 'dispatcher' AND status = 'active'
                AND next_run_at IS NOT NULL AND next_run_at <= UTC_TIMESTAMP()"
        );
        $stmt->execute([':n' => $nextRunAtUtc, ':k' => $key]);

        return $stmt->rowCount() > 0;
    }

    /** The slot after the one that was due (or after now), as the claim wants it. */
    public static function nextAfter(array $task, ?DateTimeImmutable $after = null): DateTimeImmutable
    {
        return ScheduledTasks::nextRunAtUtc((string) $task['frequency'], $task['time_of_day'], $task['day_of_week'], $after, $task['interval_minutes']);
    }

    /** The dispatcher could not start a job: hand it back to the cPanel entry so it does not silently stop. */
    public function fallBackToCpanel(string $key, string $why): void
    {
        $this->db->prepare("UPDATE system_tasks SET mode = 'cpanel', next_run_at = NULL WHERE task_key = :k")->execute([':k' => $key]);
        $this->recordRun($key, 'error', 0, 'The dispatcher could not start this job (' . $why . '), so it is back on the cPanel entry.');
    }

    /**
     * The system section of the scheduled-tasks card.
     *
     * @param list<array<string, mixed>> $tasks from listAll()
     * @return list<array<string, mixed>>
     */
    public static function cardRows(array $tasks): array
    {
        $tz    = new DateTimeZone('Europe/Copenhagen');
        $local = static fn (?string $utc): ?string => $utc === null ? null
            : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('D j M, H:i');
        $out = [];
        foreach ($tasks as $t) {
            $out[] = [
                'key'         => $t['key'],
                'label'       => $t['label'],
                'description' => $t['description'],
                'status'      => $t['status'],
                'mode'        => $t['mode'],
                'schedule'    => ScheduledTasks::describeSchedule($t['frequency'], $t['time_of_day'], $t['day_of_week'], $t['interval_minutes']),
                'next_run'    => $t['mode'] === 'dispatcher' && $t['status'] === 'active' ? $local($t['next_run_at']) : null,
                'last_run'    => $local($t['last_run_at']),
                'last_status' => $t['last_status'],
                'last_ms'     => $t['last_duration_ms'],
                'last_output' => $t['last_output'],
            ];
        }

        return $out;
    }

    private function scheduleNext(string $key): void
    {
        $t = $this->find($key);
        if ($t === null) {
            return;
        }
        $next = self::nextAfter($t);
        $this->db->prepare('UPDATE system_tasks SET next_run_at = :n WHERE task_key = :k')->execute([':n' => $next->format('Y-m-d H:i:s'), ':k' => $key]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function row(string $key, array $r): array
    {
        $c = self::CATALOG[$key];

        return [
            'key'              => $key,
            'label'            => $c['label'],
            'description'      => $c['description'],
            'script'           => $c['script'],
            'status'           => (string) $r['status'],
            'mode'             => (string) $r['mode'],
            'frequency'        => (string) $r['frequency'],
            'time_of_day'      => $r['time_of_day'] !== null ? substr((string) $r['time_of_day'], 0, 5) : null,
            'day_of_week'      => $r['day_of_week'] !== null ? (int) $r['day_of_week'] : null,
            'interval_minutes' => $r['interval_minutes'] !== null ? (int) $r['interval_minutes'] : null,
            'next_run_at'      => $r['next_run_at'] !== null ? (string) $r['next_run_at'] : null,
            'last_run_at'      => $r['last_run_at'] !== null ? (string) $r['last_run_at'] : null,
            'last_status'      => $r['last_status'] !== null ? (string) $r['last_status'] : null,
            'last_duration_ms' => $r['last_duration_ms'] !== null ? (int) $r['last_duration_ms'] : null,
            'last_output'      => $r['last_output'] !== null ? (string) $r['last_output'] : null,
        ];
    }
}
