<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailDraft;
use App\Email\EmailService;

/**
 * Tool: compose an email and save it as a DRAFT in the user's mailbox. Never
 * sends — drafting is the current ceiling (sending is locked). To reply within a
 * thread, pass thread_id from get_emails and a "Re: …" subject.
 */
final class DraftEmail implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'draft_email';
    }

    public function description(): string
    {
        return 'Writes an email and saves it as a DRAFT in the user\'s mailbox for them to review and send '
            . 'themselves. Does NOT send. Use for "draft a reply", "write an email to X". For a reply, pass '
            . 'thread_id (from get_emails) and a "Re: …" subject. account is optional when several mailboxes exist.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'to'        => ['type' => 'string', 'description' => 'Recipient email address(es), comma-separated.'],
                'subject'   => ['type' => 'string'],
                'body'      => ['type' => 'string', 'description' => 'Plain-text body.'],
                'cc'        => ['type' => 'string', 'description' => 'Optional Cc address(es).'],
                'thread_id' => ['type' => 'string', 'description' => 'Optional Gmail thread id to reply within.'],
                'account'   => ['type' => 'string', 'description' => 'Which mailbox (email or provider), if several.'],
            ],
            'required' => ['to', 'subject', 'body'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->email->hasAccounts($userId)) {
            return ['connected' => false, 'error' => 'No email account is connected yet.'];
        }
        $to   = trim((string) ($arguments['to'] ?? ''));
        $body = (string) ($arguments['body'] ?? '');
        if ($to === '' || $body === '') {
            return ['error' => 'I need at least a recipient and a body to draft an email.'];
        }

        $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);
        $draft     = new EmailDraft(
            to:       $to,
            subject:  (string) ($arguments['subject'] ?? ''),
            bodyText: $body,
            cc:       trim((string) ($arguments['cc'] ?? '')),
            threadId: isset($arguments['thread_id']) && trim((string) $arguments['thread_id']) !== ''
                ? (string) $arguments['thread_id'] : null,
        );

        try {
            $res = $this->email->createDraft($userId, $accountId, $draft);
        } catch (\Throwable $e) {
            error_log('draft_email: ' . $e->getMessage());
            return ['error' => 'I could not save that draft just now.'];
        }

        return [
            'drafted'  => true,
            'draft_id' => $res['draft_id'],
            '_render'  => [
                'kind'     => 'email_draft',
                'title'    => 'Draft saved',
                'to'       => $to,
                'cc'       => $draft->cc,
                'subject'  => $draft->subject,
                'body'     => $body,
                'note'     => 'Saved to your Drafts — review and send it yourself.',
                'sent'     => false,
            ],
        ];
    }
}
