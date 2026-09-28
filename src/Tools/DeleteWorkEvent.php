<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: delete a wrong work session (or a single stray punch) by event id. Ids come from
 * get_work_hours. By default the whole session goes — its clock-in AND clock-out — and the
 * card re-shows the period the user was looking at (report #25: deleting a 1-minute session
 * on 6 Sep left its clock-out behind and popped up TODAY's card instead of the month).
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
            . 'out_id). By default it removes the WHOLE session — clock-in and clock-out together — which is '
            . 'what "that session is wrong / I didn\'t work then" means; set whole_session=false only to drop '
            . 'one stray punch. Pass the period the user is looking at (scope, or from/to, and place — e.g. '
            . 'this month at a workplace when they are working out an invoice): the result then carries the '
            . 'UPDATED total for that period, so answer with it in this same reply (e.g. the corrected '
            . 'invoice amount) instead of promising to look it up. Only remove what the user clearly identified.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'event_id'      => ['type' => 'integer', 'description' => 'A session\'s in_id or out_id (from get_work_hours).'],
                'whole_session' => ['type' => 'boolean', 'description' => 'Delete both punches of the session (default true).'],
                'scope'         => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'week', 'lastweek', 'month', 'lastmonth'],
                    'description' => 'The period to re-show afterwards. Omit to show the deleted session\'s day.',
                ],
                'from'  => ['type' => 'string', 'description' => 'Start (YYYY-MM-DD) of the period to re-show.'],
                'to'    => ['type' => 'string', 'description' => 'End (YYYY-MM-DD, inclusive) of the period to re-show.'],
                'place' => ['type' => 'string', 'description' => 'Workplace filter for the re-shown period.'],
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

        // Re-show what the user was looking at; without a period, the deleted session's own day.
        $hasPeriod = isset($arguments['scope']) || !empty($arguments['from']) || !empty($arguments['to']);
        $period    = GetWorkHours::periodArgs(
            $hasPeriod ? $arguments : ['date' => $session['date'] ?? $event['local_date'], 'place' => $arguments['place'] ?? null]
        );
        if (isset($period['error'])) {
            $period = ['scope' => 'today', 'date' => $session['date'] ?? $event['local_date'], 'to' => null, 'place' => null];
        }
        $summary = $this->events->summary($userId, $period['scope'], $period['date'], $period['place'], $period['to']);

        $out = [
            'deleted'     => true,
            'event_ids'   => $deleted,
            'removed'     => $session !== null
                ? array_intersect_key($session, array_flip(['place', 'day', 'in', 'out', 'minutes']))
                : ['punch' => $event['kind'], 'date' => $event['local_date']],
            'now_showing' => [
                'range'         => $summary['range_label'],
                'place'         => $period['place'],
                'total'         => $summary['total_label'],
                'total_minutes' => $summary['total_minutes'],
                'by_place'      => $summary['places'],
            ],
            '_render'     => $summary['card'],
        ];
        if (!$hasPeriod) {
            $out['hint'] = 'If the user was looking at a longer period (a month, an invoice), call get_work_hours '
                . 'for it now and answer with the new total — do not just promise to.';
        }

        return $out;
    }
}
