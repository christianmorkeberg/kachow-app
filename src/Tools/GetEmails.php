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
            . 'WITHOUT account it looks in EVERY connected mailbox (Gmail, Hotmail, IMAP …) and merges the '
            . 'results by date; pass account (email address or "gmail"/"hotmail") only when the user names one. '
            . 'The result lists "searched" (each mailbox and how many it returned) and "failed" (mailboxes that '
            . 'could not be reached) — never say you searched a mailbox that is not in "searched". '
            . 'Each email has an id plus its "account"; pass both to read_email. Read-only — never sends anything.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'unread_only' => ['type' => 'boolean', 'description' => 'Only unread messages.'],
                'query'       => ['type' => 'string', 'description' => 'Optional search (Gmail query syntax).'],
                'limit'       => ['type' => 'integer', 'description' => 'Max messages (default 12, max 25).'],
                'account'     => ['type' => 'string', 'description' => 'Only this mailbox (email or provider). Omit to search all of them.'],
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

        $hint      = isset($arguments['account']) ? trim((string) $arguments['account']) : '';
        $accountId = $this->email->matchAccount($userId, $hint !== '' ? $hint : null);
        $limit     = (int) ($arguments['limit'] ?? 12);

        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : null;
        if (!empty($arguments['unread_only'])) {
            $query = trim(($query ?? '') . ' is:unread');
        }

        try {
            $res = $this->email->listAcross($userId, $accountId, $limit, $query !== '' ? $query : null);
        } catch (\Throwable $e) {
            error_log('get_emails: ' . $e->getMessage());
            return ['error' => 'I could not reach ' . ($accountId !== null ? 'that mailbox' : 'your mailboxes') . ' just now.'];
        }

        // Every message says which mailbox it is in — read_email / mark_emails_read need it, and the card opens
        // the right one even when the list mixes mailboxes.
        $items = array_map(
            static fn (array $r): array => $r['message']->toArray(false)
                + ['account' => $r['account']['email'], 'account_id' => $r['account']['id']],
            $res['messages']
        );

        $out = [
            'connected' => true,
            'count'     => count($items),
            'searched'  => $res['searched'],
            'emails'    => $items,
            '_render'   => [
                'kind'       => 'email_list',
                'title'      => $query !== null && $query !== '' ? 'Email search' : 'Recent email',
                'account_id' => $accountId,
                'items'      => $items,
            ],
        ];
        if ($res['failed'] !== []) {
            $out['failed'] = $res['failed'];
        }
        $unmatched = $hint !== '' && $accountId === null;
        if ($unmatched || $res['failed'] !== [] || count($res['searched']) > 1) {
            $out['note'] = ($unmatched ? 'No mailbox matched "' . $hint . '", so every connected mailbox was searched. ' : '')
                . 'Only claim to have searched the mailboxes listed in "searched"'
                . ($res['failed'] !== [] ? ' — and say plainly that those in "failed" could not be reached, so nothing is known about them' : '')
                . '. Each email shows its mailbox in "account": pass that to read_email / mark_emails_read.';
        }

        return $out;
    }
}
