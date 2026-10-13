<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ScheduledTasks;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: list the user's active scheduled (recurring) tasks.
 */
final class ListScheduledTasks implements Tool
{
    public function __construct(private ScheduledTasks $tasks)
    {
    }

    public function name(): string
    {
        return 'list_scheduled_tasks';
    }

    public function description(): string
    {
        return 'Lists the user\'s active recurring scheduled tasks (set up via schedule_task) — what each one '
            . 'does, how often, and when it next runs. Use for "what have I scheduled?", "what are my '
            . 'recurring checks?". Each result\'s id is used by cancel_scheduled_task.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $tz    = new DateTimeZone('Europe/Copenhagen');
        $items = array_map(static function (array $t) use ($tz): array {
            $next = (new DateTimeImmutable($t['next_run_at'], new DateTimeZone('UTC')))->setTimezone($tz);

            return [
                'id'               => $t['id'],
                'label'            => $t['label'],
                'instruction'      => $t['instruction'],
                'frequency'        => $t['frequency'],
                'time_of_day'      => $t['time_of_day'],
                'next_run_local'   => $next->format('D j M, H:i'),
            ];
        }, $this->tasks->listForUser($userId));

        return [
            'count' => count($items),
            'tasks' => $items,
        ];
    }
}
