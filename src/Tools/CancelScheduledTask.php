<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;

/**
 * Tool: cancel a recurring scheduled task. Thin wrapper over Data\ScheduledTasks::cancel.
 */
final class CancelScheduledTask implements Tool
{
    public function __construct(private ScheduledTasks $tasks)
    {
    }

    public function name(): string
    {
        return 'cancel_scheduled_task';
    }

    public function description(): string
    {
        return 'Cancels a recurring scheduled task so it stops running. First call list_scheduled_tasks to '
            . 'find its id if you don\'t already have it. The task\'s past runs/conversation are kept, only '
            . 'future runs stop.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The scheduled task id (from list_scheduled_tasks).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Which scheduled task? Give its id (from list_scheduled_tasks).'];
        }

        $cancelled = $this->tasks->cancel($userId, $id);

        return $cancelled
            ? ['cancelled' => true]
            : ['cancelled' => false, 'error' => 'No matching active scheduled task found (it may already be cancelled).'];
    }
}
