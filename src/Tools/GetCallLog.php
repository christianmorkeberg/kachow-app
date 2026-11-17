<?php

declare(strict_types=1);

namespace App\Tools;

use App\Diagnostics\ToolCallLog;

/**
 * Tool: the raw log of what the assistant did — every tool call with its arguments and what came back (the user's own only). For "what did you just do",
 * "what did you delete", or to re-create something that was lost: read the arguments/result of the call and redo it with the right tool.
 */
final class GetCallLog implements Tool
{
    public function __construct(private ToolCallLog $log)
    {
    }

    public function name(): string
    {
        return 'get_call_log';
    }

    public function description(): string
    {
        return 'Shows the raw log of what the assistant did: each tool call with its arguments and its result (newest first). Use it when the user asks what you did/deleted/changed, '
            . 'or when something is missing and needs to be found and re-created ("what did you delete earlier?", "hvad gjorde du", "show the log"). Filter by `tool` and `days`. '
            . 'Values are trimmed and secrets are masked. To put back a logged work/cash change use restore_change; otherwise re-create it from the logged values with the right tool.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'tool'  => ['type' => 'string', 'description' => 'Only calls of this tool, e.g. "delete_work_event".'],
                'days'  => ['type' => 'integer', 'description' => 'How far back (default 7, max 180).'],
                'limit' => ['type' => 'integer', 'description' => 'How many calls (default 20, max 50).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $tool = isset($arguments['tool']) && trim((string) $arguments['tool']) !== '' ? trim((string) $arguments['tool']) : null;
        try {
            $rows = $this->log->rawCalls($userId, $tool, (int) ($arguments['days'] ?? 7), (int) ($arguments['limit'] ?? 20));
        } catch (\Throwable) {
            return ['available' => false, 'note' => 'The call log is not set up on the server.'];
        }
        // A result can be long: show the start of it, the rest stays in the database.
        foreach ($rows as &$r) {
            $res = json_encode($r['result'], JSON_UNESCAPED_UNICODE);
            if (is_string($res) && strlen($res) > 1500) {
                $r['result'] = mb_substr($res, 0, 1500) . '…[cut for display]';
            }
        }
        unset($r);

        return ['calls' => $rows, 'count' => count($rows), 'note' => 'Times are UTC. Arguments/results exist only for calls made after the raw log was switched on.'];
    }
}
