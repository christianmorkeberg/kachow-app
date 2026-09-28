<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: delete a wrong work session (or a single stray punch) by event id. Ids come from
 * get_work_hours. A session is deleted whole — its clock-in AND clock-out — since half a
 * session is corrupt data (a stray out, or an out-less "forgotten" session). It only
 * deletes: showing the result is the model's job (get_work_hours), not this tool's.
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
        return 'Deletes a wrong work session by an event id from get_work_hours (a session\'s in_id or '
            . 'out_id) — clock-in and clock-out together. Set whole_session=false only to drop one stray '
            . 'punch. Only remove what the user clearly identified.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id'      => ['type' => 'integer', 'description' => 'A session\'s in_id or out_id (from get_work_hours).'],
                'whole_session' => ['type' => 'boolean', 'description' => 'Delete both punches of the session (default true).'],
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
        $event = $this->events->find($userId, $id);
        if ($event === null) {
            return ['deleted' => false, 'error' => 'No such event (it may already be gone).'];
        }

        $whole   = !array_key_exists('whole_session', $arguments) || filter_var($arguments['whole_session'], FILTER_VALIDATE_BOOL);
        $session = $whole ? $this->events->sessionOf($userId, $id) : null;
        $ids     = $session !== null ? array_values(array_filter([$session['in_id'], $session['out_id']])) : [$id];

        $deleted = [];
        foreach ($ids as $eid) {
            if ($this->events->delete($userId, (int) $eid)) {
                $deleted[] = (int) $eid;
            }
        }
        if ($deleted === []) {
            return ['deleted' => false, 'error' => 'No such event (it may already be gone).'];
        }

        return [
            'deleted'   => true,
            'event_ids' => $deleted,
            'removed'   => $session !== null
                ? array_intersect_key($session, array_flip(['place', 'date', 'in', 'out', 'minutes']))
                : ['punch' => $event['kind'], 'date' => $event['local_date']],
        ];
    }
}
