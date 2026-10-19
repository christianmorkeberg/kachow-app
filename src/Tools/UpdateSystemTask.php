<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use App\Data\SystemTasks;
use App\Data\Users;

/**
 * Tool (developer only): change a built-in system job's schedule and/or who starts it.
 */
final class UpdateSystemTask implements Tool
{
    public function __construct(
        private SystemTasks $system,
        private ScheduledTasks $tasks,
        private Users $users,
    ) {
    }

    public function name(): string
    {
        return 'update_system_task';
    }

    public function description(): string
    {
        return 'Developer only: changes a built-in system job\'s schedule and/or its trigger. Jobs: ' . implode(', ', array_column(SystemTasks::CATALOG, 'label'))
            . '. `trigger` = "cpanel" (the server\'s own cron entry starts it, as it always has; the schedule shown is then only what that '
            . 'entry does) or "dispatcher" (the per-minute scheduled-tasks cron starts it on the schedule you set — switching a job '
            . 'to the dispatcher makes this schedule real, and its old cPanel entry becomes a harmless no-op). Give frequency '
            . '(minutes | daily | weekdays | weekly) with interval_minutes (min 5) / time_of_day (HH:MM, Copenhagen) / day_of_week '
            . '(1 Monday … 7 Sunday). Use for "run the notifications every 15 minutes", "move the location freeze to 05:00", '
            . '"let the dispatcher run the reminders". To pause or resume use set_scheduled_task_active.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'system_task'      => ['type' => 'string', 'description' => 'The job\'s name.'],
                'frequency'        => ['type' => 'string', 'enum' => SystemTasks::FREQUENCIES],
                'interval_minutes' => ['type' => 'integer', 'description' => 'For frequency "minutes": every N minutes (5–1440).'],
                'time_of_day'      => ['type' => 'string', 'description' => 'HH:MM local time, for daily / weekdays / weekly.'],
                'day_of_week'      => ['type' => 'integer', 'description' => '1 = Monday … 7 = Sunday, for weekly.'],
                'trigger'          => ['type' => 'string', 'enum' => SystemTasks::MODES, 'description' => 'Who starts the job: cpanel or dispatcher.'],
            ],
            'required' => ['system_task'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Only the developer can change the built-in system jobs.'];
        }
        $key = SystemTasks::resolveKey((string) ($arguments['system_task'] ?? ''));
        if ($key === null) {
            return ['error' => 'Which job? They are: ' . implode(', ', array_column(SystemTasks::CATALOG, 'label')) . '.'];
        }
        $freq = isset($arguments['frequency']) ? (string) $arguments['frequency'] : null;
        $time = isset($arguments['time_of_day']) ? (string) $arguments['time_of_day'] : null;
        $dow  = isset($arguments['day_of_week']) ? (int) $arguments['day_of_week'] : null;
        $int  = isset($arguments['interval_minutes']) ? (int) $arguments['interval_minutes'] : null;
        $mode = isset($arguments['trigger']) ? (string) $arguments['trigger'] : null;
        if ($freq === null && $time === null && $dow === null && $int === null && $mode === null) {
            return ['error' => 'Nothing to change — pass frequency / interval_minutes / time_of_day / day_of_week and/or trigger.'];
        }
        $why = $this->system->update($key, $freq, $time, $dow, $int, $mode);
        if ($why !== null) {
            return ['ok' => false, 'error' => $why];
        }
        $t = $this->system->find($key);
        $rows = SystemTasks::cardRows([$t]);

        return [
            'ok'           => true,
            'system_task'  => $t['label'],
            'schedule'     => $rows[0]['schedule'],
            'triggered_by' => $t['mode'] === 'dispatcher' ? 'the per-minute dispatcher' : 'the cPanel cron entry',
            'note'         => $t['mode'] === 'dispatcher'
                ? 'The dispatcher now starts it on this schedule; the old cPanel entry does nothing any more. If this server cannot start '
                    . 'processes the job goes back to cPanel by itself and says so in its last-run result.'
                : 'Still started by the cPanel cron entry, so this schedule is informational only — switch the trigger to "dispatcher" to make it real.',
            '_render'      => ScheduledTasks::card($this->tasks->listForCard($userId), SystemTasks::cardRows($this->system->listAll())),
        ];
    }
}
