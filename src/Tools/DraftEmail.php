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
    public function __construct(private EmailService $email, private ?ExportData $export = null)
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
                'attach_export_url' => ['type' => 'string', 'description' => 'Attach an export (timesheet / table) made with export_data: pass its pdf_url (or csv_url) exactly as returned. The file is attached when the email is sent.'],
                'account'   => ['type' => 'string', 'description' => 'The mailbox to send FROM (email address, or provider like "gmail"/"hotmail"). ALWAYS pass this when the user names a sender/from-address; otherwise the default mailbox is used.'],
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

        $attachUrl   = trim((string) ($arguments['attach_export_url'] ?? ''));
        $attachments = [];
        if ($attachUrl !== '') {
            $file = $this->export?->attachment($attachUrl, $userId) ?? ['error' => 'attachments are not available here'];
            if (isset($file['error'])) {
                return ['error' => 'I could not attach that file: ' . $file['error']];
            }
            $attachments[] = $file;
        }

        $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);
        $draft     = new EmailDraft(
            to:       $to,
            subject:  (string) ($arguments['subject'] ?? ''),
            bodyText: $body,
            cc:       trim((string) ($arguments['cc'] ?? '')),
            threadId: isset($arguments['thread_id']) && trim((string) $arguments['thread_id']) !== ''
                ? (string) $arguments['thread_id'] : null,
            attachments: $attachments,
        );

        try {
            $res = $this->email->createDraft($userId, $accountId, $draft);
        } catch (\Throwable $e) {
            error_log('draft_email: ' . $e->getMessage());
            return ['error' => 'I could not save that draft just now.'];
        }

        $canSend = $this->email->sendEnabled();

        return [
            'drafted'  => true,
            'draft_id' => $res['draft_id'],
            '_render'  => [
                'kind'         => 'email_draft',
                'title'        => 'Draft ready',
                'from'         => $res['account_email'],
                'to'           => $to,
                'cc'           => $draft->cc,
                'subject'      => $draft->subject,
                'body'         => $body,
                'note'         => $canSend
                    ? 'Review it, then tap Send.'
                    : 'Saved to your Drafts — review and send it yourself.',
                'sent'         => false,
                'account_id'   => $res['account_id'],
                'draft_id'     => $res['draft_id'],
                'thread_id'    => $draft->threadId,
                'send_enabled' => $canSend,
                'attachments'  => $attachments === [] ? [] : [['name' => $attachments[0]['filename'], 'url' => $attachUrl]],
            ],
        ];
    }
}
