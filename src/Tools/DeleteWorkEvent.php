<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: delete a single work clock event by id (to remove a stray/duplicate
 * punch). Ids come from get_work_hours.
 */
final class DeleteWorkEvent implements Tool
{
    public function __construct(private WorkEvents $events)
    {
    }

    public function name(): string
    {
        return 'delete_work_event';
    }

    public function description(): string
    {
        return 'Deletes one work clock event by its id (e.g. an accidental or duplicate clock-in). '
            . 'Get the id from get_work_hours (each session has in_id / out_id). Only remove an event '
            . 'the user clearly identified.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'description' => 'The id of the event to delete.'],
            ],
            'required' => ['event_id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['event_id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A valid event_id is required (from get_work_hours).'];
        }

        $deleted = $this->events->delete($userId, $id);
        if (!$deleted) {
            return ['deleted' => false, 'error' => 'No such event (it may already be gone).'];
        }

        $summary = $this->events->summary($userId, 'today');

        return ['deleted' => true, 'total_today' => $summary['total_label'], '_render' => $summary['card']];
    }
}
