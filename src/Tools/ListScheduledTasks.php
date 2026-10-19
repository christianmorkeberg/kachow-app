<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use App\Data\SystemTasks;
use App\Data\Users;

/**
 * Tool: list the user's scheduled tasks (and, for the developer, the system jobs) and show them as the
 * scheduled-tasks card — status, schedule, next run and what the last run came to, with pause/resume.
 */
final class ListScheduledTasks implements Tool
{
    public function __construct(
        private ScheduledTasks $tasks,
        private ?SystemTasks $system = null,
        private ?Users $users = null,
    ) {
    }

    public function name(): string
    {
        return 'list_scheduled_tasks';
    }

    public function description(): string
    {
        return 'Shows the user\'s scheduled tasks (set up via schedule_task) as a card — active and paused ones, '
            . 'what each does, how often (every N minutes, hourly, daily, weekdays, weekly, or once), when it next '
            . 'runs, and what its LAST RUN came to (whether it notified, and its closing words or error) — '
            . 'with pause/resume on each. Use for "what have I scheduled?", "what are my recurring checks?", '
            . '"what did the invoice check say last time?". The developer also gets the built-in system jobs '
            . '(notifications, reminders, conversation starters, home device list, memory cleanup, location day freeze): '
            . 'schedule, who triggers them, last run result. Each task\'s id is used by set_scheduled_task_active and '
            . 'cancel_scheduled_task; system jobs by their name. The card shows everything — summarise, don\'t re-list.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $isAdmin = $this->system !== null && $this->users !== null && $this->users->isAdmin($userId);
        $tasks   = $this->tasks->listForCard($userId);
        $system  = $isAdmin ? SystemTasks::cardRows($this->system->listAll()) : null;
        $card    = ScheduledTasks::card($tasks, $system);

        $out = [
            'count'   => count($tasks),
            'tasks'   => array_map(static fn (array $t): array => [
                'id'          => $t['id'],
                'label'       => $t['label'],
                'status'      => $t['status'],
                'schedule'    => $t['schedule'],
                'next_run'    => $t['next_run'],
                'last_run'    => $t['last_run'],
                'last_result' => $t['last_run'] === null ? null : ($t['last_status'] === 'error' ? 'error' : ($t['notified'] ? 'notified' : 'nothing to report')),
                'last_title'  => $t['notified'] ? $t['last_title'] : null,
                'last_said'   => $t['last_summary'] !== null ? mb_substr($t['last_summary'], 0, 300) : null,
                'tell_always' => $t['tell_always'],
            ], $card['items']),
            '_render' => $card,
        ];
        if ($system !== null) {
            $out['system_tasks'] = array_map(static fn (array $t): array => [
                'name'        => $t['label'],
                'status'      => $t['status'],
                'schedule'    => $t['schedule'],
                'triggered_by' => $t['mode'] === 'dispatcher' ? 'the per-minute dispatcher' : 'the cPanel cron entry',
                'last_run'    => $t['last_run'],
                'last_result' => $t['last_status'],
            ], $system);
        }

        return $out;
    }
}
