<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailService;

/**
 * Tool: list recent emails from a connected mailbox (optionally unread-only or a
 * search). Read-only. Returns compact summaries with ids for read_email/draft_email.
 */
final class GetEmails implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'get_emails';
    }

    public function description(): string
    {
        return 'Lists recent emails from the user\'s connected mailbox (most recent first). '
            . 'Use for "check my email", "any new mail", "unread emails", or searching ("emails from X"). '
            . 'Set unread_only for just unread. Provide query for a search (Gmail syntax, e.g. "from:bank"). '
            . 'account is optional (email address or "gmail") when they have more than one mailbox. '
            . 'Each result has an id used by read_email and draft_email. Read-only — never sends anything.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'unread_only' => ['type' => 'boolean', 'description' => 'Only unread messages.'],
                'query'       => ['type' => 'string', 'description' => 'Optional search (Gmail query syntax).'],
                'limit'       => ['type' => 'integer', 'description' => 'Max messages (default 12, max 25).'],
                'account'     => ['type' => 'string', 'description' => 'Which mailbox (email or provider), if several.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->email->hasAccounts($userId)) {
            return [
                'connected' => false,
                'message'   => 'No email account is connected yet. The user can connect one from the menu (Connect email).',
                '_render'   => ['kind' => 'notice', 'tone' => 'info', 'title' => 'No email connected',
                    'detail' => 'Connect a mailbox from the menu to let me read and draft email.'],
            ];
        }

        $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);
        $limit     = (int) ($arguments['limit'] ?? 12);

        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : null;
        if (!empty($arguments['unread_only'])) {
            $query = trim(($query ?? '') . ' is:unread');
        }

        try {
            $messages = $this->email->listRecent($userId, $accountId, $limit, $query !== '' ? $query : null);
        } catch (\Throwable $e) {
            error_log('get_emails: ' . $e->getMessage());
            return ['error' => 'I could not reach that mailbox just now.'];
        }

        $items = array_map(static fn ($m) => $m->toArray(false), $messages);

        return [
            'connected' => true,
            'count'     => count($items),
            'emails'    => $items,
            '_render'   => [
                'kind'      => 'email_list',
                'title'     => $query !== null && $query !== '' ? 'Email search' : 'Recent email',
                'account_id' => $accountId,
                'items'     => $items,
            ],
        ];
    }
}
