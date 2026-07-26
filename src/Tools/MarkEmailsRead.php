<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailService;

/**
 * Tool: mark email(s) as read. Either all currently-unread inbox mail (the common
 * "mark them all as read"), or specific messages by id (from get_emails).
 */
final class MarkEmailsRead implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'mark_emails_read';
    }

    public function description(): string
    {
        return 'Marks email as read. With no ids, marks ALL currently-unread inbox messages as read '
            . '("mark them all as read", Danish "markér alle mails som læst"). Pass "ids" (from get_emails) '
            . 'to mark only those specific messages. "account" is optional when the user has several '
            . 'mailboxes. Confirm the count back to the user.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Specific email ids to mark read (from get_emails). Omit to mark ALL unread.',
                ],
                'account' => ['type' => 'string', 'description' => 'Which mailbox (email or provider), if several.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->email->hasAccounts($userId)) {
            return ['connected' => false, 'error' => 'No email account is connected yet.'];
        }

        $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);
        $ids       = isset($arguments['ids']) && is_array($arguments['ids']) ? $arguments['ids'] : [];

        try {
            $marked = $ids !== []
                ? $this->email->markReadMany($userId, $accountId, $ids)
                : $this->email->markAllRead($userId, $accountId);
        } catch (\Throwable $e) {
            error_log('mark_emails_read: ' . $e->getMessage());

            return ['error' => 'I could not mark those as read just now.'];
        }

        return [
            'marked_read' => $marked,
            'scope'       => $ids !== [] ? 'selected' : 'all_unread',
            'message'     => $marked === 0
                ? 'There were no unread emails to mark.'
                : 'Marked ' . $marked . ' email' . ($marked === 1 ? '' : 's') . ' as read.',
        ];
    }
}
