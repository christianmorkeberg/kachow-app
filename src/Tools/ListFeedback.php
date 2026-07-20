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
            . 'report", "show new bug reports", "more info on report N". Each report includes who sent '
            . 'it, their note, the reported message, the SURROUNDING conversation (the messages leading '
            . 'up to it, including tool results), the routing, tool calls, model, and the model\'s '
            . 'thoughts if captured — so you can answer follow-up questions like "what messages were '
            . 'sent" directly from this. Default shows new (unresolved) reports.';
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
            $snap     = json_decode((string) $r['snapshot'], true);
            $snap     = is_array($snap) ? $snap : [];
            $reported = is_array($snap['reported'] ?? null) ? $snap['reported'] : [];
            $diag     = is_array($reported['diagnostics'] ?? null) ? $reported['diagnostics'] : [];

            // The surrounding conversation the reporter effectively shared (a window of
            // messages ending at the reported one), so the developer sees what led to it.
            $context = [];
            foreach (($snap['context'] ?? []) as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $context[] = [
                    'role'      => $m['role'] ?? null,
                    'tool'      => $m['tool_name'] ?? null,
                    'text'      => isset($m['content']) ? mb_substr((string) $m['content'], 0, 1000) : null,
                ];
            }
            // The window ends at the reported message, so mark the last item as such.
            if ($context !== []) {
                $context[count($context) - 1]['reported'] = true;
            }

            $out[] = [
                'id'              => (int) $r['id'],
                'when'            => (string) $r['created_at'],
                'status'          => (string) $r['status'],
                'from'            => (string) ($r['reporter_name'] ?: $r['reporter_email']),
                'note'            => $r['note'] !== null ? (string) $r['note'] : null,
                'conversation_id' => $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null,
                'reported_role'   => $reported['role'] ?? null,
                'reported_text'   => isset($reported['content'])
                    ? mb_substr((string) $reported['content'], 0, 2000) : null,
                'card_kind'       => $reported['card_kind'] ?? null,
                'routing'         => $diag['routing'] ?? null,
                'model'           => $diag['model'] ?? null,
                'tool_calls'      => $diag['calls'] ?? null,
                'thoughts'        => $diag['thoughts'] ?? null,
                'conversation'    => $context,
            ];
        }

        $card = [
            'kind'      => 'feedback',
            'status'    => $status,
            'new_total' => $this->reports->countByStatus('new'),
            'reports'   => $out,
        ];

        // The card carries the full detail (thread, diagnostics, resolve button); give
        // the model only a compact summary so it writes a short intro, not a re-list.
        $brief = array_map(static fn (array $r): array => [
            'id'     => $r['id'],
            'from'   => $r['from'],
            'status' => $r['status'],
            'note'   => $r['note'],
        ], $out);

        return [
            'status'    => $status,
            'count'     => count($out),
            'new_total' => $card['new_total'],
            'reports'   => $brief,
            '_render'   => $card,
            'hint'      => 'A card now shows each report with its full conversation thread, diagnostics '
                . 'and a resolve button. Give a SHORT intro (e.g. how many new reports, who from); do '
                . 'NOT re-list the messages or details as text — the card shows them.',
        ];
    }
}
