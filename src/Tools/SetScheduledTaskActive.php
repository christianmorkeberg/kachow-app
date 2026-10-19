<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use App\Data\SystemTasks;
use App\Data\Users;

/**
 * Tool: pause or resume a scheduled task (by id) or, for the developer, a system job (by name), then show
 * the scheduled-tasks card. Pausing keeps the task and its history; cancel_scheduled_task ends it for good.
 */
final class SetScheduledTaskActive implements Tool
{
    public function __construct(
        private ScheduledTasks $tasks,
        private ?SystemTasks $system = null,
        private ?Users $users = null,
    ) {
    }

    public function name(): string
    {
        return 'set_scheduled_task_active';
    }

    public function description(): string
    {
        return 'Pauses (active=false) or resumes (active=true) a scheduled task by `id` (from list_scheduled_tasks), or — '
            . 'developer only — a built-in system job by `system_task` name (e.g. "notifications", "location freeze"). '
            . 'A paused task keeps its schedule and history but does not run; resuming picks the next slot from now. '
            . 'Use for "pause the invoice check", "turn the lights routine back on", "stop the memory cleanup for now", '
            . 'Danish "sæt … på pause", "genoptag …", "aktivér/deaktiver …". To end a task for good use cancel_scheduled_task.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'          => ['type' => 'integer', 'description' => 'The scheduled task id.'],
                'system_task' => ['type' => 'string', 'description' => 'A system job\'s name, instead of an id (developer only).'],
                'active'      => ['type' => 'boolean', 'description' => 'true = resume, false = pause.'],
            ],
            'required' => ['active'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!array_key_exists('active', $arguments)) {
            return ['error' => 'Say whether to pause (active=false) or resume (active=true).'];
        }
        $active = filter_var($arguments['active'], FILTER_VALIDATE_BOOLEAN);
        $isAdmin = $this->system !== null && $this->users !== null && $this->users->isAdmin($userId);

        $name = trim((string) ($arguments['system_task'] ?? ''));
        if ($name !== '') {
            if (!$isAdmin) {
                return ['error' => 'Only the developer can change the built-in system jobs.'];
            }
            $key = SystemTasks::resolveKey($name);
            if ($key === null) {
                return ['error' => 'No system job called "' . $name . '". They are: ' . implode(', ', array_column(SystemTasks::CATALOG, 'label')) . '.'];
            }
            $this->system->setActive($key, $active);

            return ['ok' => true, 'system_task' => SystemTasks::CATALOG[$key]['label'], 'status' => $active ? 'active' : 'paused', '_render' => $this->card($userId, $isAdmin)];
        }

        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Which task? Give its id (from list_scheduled_tasks).'];
        }
        if ($active) {
            $t = $this->tasks->find($userId, $id);
            if ($t !== null && $t['status'] === 'paused' && $this->tasks->countActive($userId) >= ScheduleTask::MAX_ACTIVE_PER_USER) {
                return ['error' => 'You already have ' . ScheduleTask::MAX_ACTIVE_PER_USER . ' scheduled tasks running — pause or cancel one first.'];
            }
        }
        $why = $this->tasks->setActive($userId, $id, $active);
        if ($why !== null) {
            return ['ok' => false, 'error' => $why];
        }
        $t = $this->tasks->find($userId, $id);

        return ['ok' => true, 'id' => $id, 'label' => $t['label'] ?? null, 'status' => $active ? 'active' : 'paused', '_render' => $this->card($userId, $isAdmin)];
    }

    /** @return array<string, mixed> */
    private function card(int $userId, bool $isAdmin): array
    {
        return ScheduledTasks::card($this->tasks->listForCard($userId), $isAdmin ? SystemTasks::cardRows($this->system->listAll()) : null);
    }
}
