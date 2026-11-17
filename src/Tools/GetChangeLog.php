<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ChangeLog;

/** Tool: what the assistant recently changed or deleted (work sessions, cash movements), with ids for restore_change. */
final class GetChangeLog implements Tool
{
    public function __construct(private ChangeLog $log)
    {
    }

    public function name(): string
    {
        return 'get_change_log';
    }

    public function description(): string
    {
        return 'Lists what was recently changed or deleted through the assistant (work sessions, cash movements), newest first, each with an id. Use it when the user asks what was changed/deleted, '
            . 'or wants something back ("bring back what you deleted", "gendan", "what did you just change?"). Then restore_change(id) puts one back.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'entity' => ['type' => 'string', 'enum' => ['work_session', 'cash_movement'], 'description' => 'Only this kind. Omit for all.'],
                'limit'  => ['type' => 'integer', 'description' => 'How many (default 15, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->log->available()) {
            return ['available' => false, 'note' => 'The change log is not set up on the server yet (migration 2026-11-17_change_log.sql), so nothing has been recorded.'];
        }
        $entity = isset($arguments['entity']) && in_array($arguments['entity'], ['work_session', 'cash_movement'], true) ? (string) $arguments['entity'] : null;

        return ['available' => true, 'changes' => $this->log->recent($userId, (int) ($arguments['limit'] ?? 15), $entity)];
    }
}
