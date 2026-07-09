<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\WorkEvents;

/**
 * Tool: manually record or correct a work clock in/out — e.g. "I arrived at 8:30",
 * or "I forgot to clock out yesterday, I left at 17:00". Complements the automatic
 * geofence punches.
 */
final class LogWorkEvent implements Tool
{
    public function __construct(private WorkEvents $events)
    {
    }

    public function name(): string
    {
        return 'log_work_event';
    }

    public function description(): string
    {
        return 'Manually records a work clock-in or clock-out, for corrections or when the automatic '
            . 'trigger was missed (e.g. "I forgot to clock out yesterday, I left at 17:00"). Provide '
            . '"at" as an RFC3339 UTC timestamp; omit it to use now. To clock out a session, log an '
            . '"out" at the time they left. Use get_work_hours first if you need to see current state.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'kind' => [
                    'type'        => 'string',
                    'enum'        => ['in', 'out'],
                    'description' => '"in" for arriving/clocking in, "out" for leaving/clocking out.',
                ],
                'at' => [
                    'type'        => 'string',
                    'description' => 'When it happened, RFC3339 UTC (e.g. "2026-07-08T15:00:00Z"). Omit for now.',
                ],
                'note' => [
                    'type'        => 'string',
                    'description' => 'Optional short note.',
                ],
            ],
            'required' => ['kind'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $kind = (string) ($arguments['kind'] ?? '');
        if ($kind !== 'in' && $kind !== 'out') {
            return ['error' => 'kind must be "in" or "out".'];
        }
        $at   = isset($arguments['at']) ? (string) $arguments['at'] : null;
        $note = isset($arguments['note']) ? (string) $arguments['note'] : null;

        try {
            $res = $this->events->add($userId, $kind, $at, 'assistant', $note);
        } catch (\Exception $e) {
            return ['error' => 'I could not read that time. Give it as a clear date/time.'];
        }

        // Show the freshly-updated day so the user sees the effect.
        $summary = $this->events->summary($userId, 'today');

        return [
            'recorded'      => $res['status'] === 'ok',
            'duplicate'     => $res['status'] === 'duplicate',
            'kind'          => $res['kind'],
            'at'            => $res['local'],
            'total_today'   => $summary['total_label'],
            'clocked_in'    => $summary['ongoing'],
            '_render'       => $summary['card'],
        ];
    }
}
