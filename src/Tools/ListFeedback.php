<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\FeedbackReports;
use App\Data\Users;

/**
 * Tool (admin/developer only): list the feedback reports users have sent about specific
 * messages ("send to developer"). Lets the developer review what looked off, with the
 * reported message and its diagnostics.
 */
final class ListFeedback implements Tool
{
    public function __construct(
        private Users $users,
        private FeedbackReports $reports,
    ) {
    }

    public function name(): string
    {
        return 'list_feedback';
    }

    public function description(): string
    {
        return 'Developer/admin only: lists feedback reports users flagged from the chat ("report to '
            . 'developer" / "send to developer"). Use for "any feedback reports?", "what did users '
            . 'report", "show new bug reports". Each report includes who sent it, their note, the '
            . 'reported message and its diagnostics. Default shows new (unresolved) reports.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['new', 'seen', 'resolved', 'all'],
                    'description' => 'Which reports to show. Default "new".',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Max reports (default 20).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Feedback reports are only available to the developer/admin.'];
        }

        $status = isset($arguments['status']) ? (string) $arguments['status'] : 'new';
        $limit  = isset($arguments['limit']) && $arguments['limit'] !== '' ? (int) $arguments['limit'] : 20;
        $rows   = $this->reports->recent($status === 'all' ? null : $status, $limit);

        $out = [];
        foreach ($rows as $r) {
            $snap = json_decode((string) $r['snapshot'], true);
            $snap = is_array($snap) ? $snap : [];
            $out[] = [
                'id'              => (int) $r['id'],
                'when'            => (string) $r['created_at'],
                'status'          => (string) $r['status'],
                'from'            => (string) ($r['reporter_name'] ?: $r['reporter_email']),
                'note'            => $r['note'] !== null ? (string) $r['note'] : null,
                'conversation_id' => $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null,
                'reported_role'   => $snap['reported']['role'] ?? null,
                'reported_text'   => isset($snap['reported']['content'])
                    ? mb_substr((string) $snap['reported']['content'], 0, 600) : null,
                'routing'         => $snap['reported']['diagnostics']['routing'] ?? null,
                'tool_calls'      => $snap['reported']['diagnostics']['calls'] ?? null,
            ];
        }

        return [
            'status'  => $status,
            'count'   => count($out),
            'new_total' => $this->reports->countByStatus('new'),
            'reports' => $out,
            'hint'    => 'Summarise these for the developer. Use resolve_feedback to mark one done.',
        ];
    }
}
