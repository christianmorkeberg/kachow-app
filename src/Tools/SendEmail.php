<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailDraft;
use App\Email\EmailService;

/**
 * Tool: prepare an email for sending. The assistant NEVER sends autonomously —
 * it drafts the message and presents it with a Send button for the user to
 * confirm with one tap. (If sending is turned off entirely, it's just saved as a
 * draft to send from their mail app.)
 */
final class SendEmail implements Tool
{
    public function __construct(private EmailService $email, private ?ExportData $export = null)
    {
    }

    public function name(): string
    {
        return 'send_email';
    }

    public function description(): string
    {
        return 'Prepares an email the user asked to send: writes it, saves it as a draft, and shows it '
            . 'with a Send button for the user to confirm — it does NOT send on its own. Use when the user '
            . 'says to send/email someone. For a reply, pass thread_id (from get_emails). account is '
            . 'optional when several mailboxes exist.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'to'        => ['type' => 'string', 'description' => 'Recipient email address(es), comma-separated.'],
                'subject'   => ['type' => 'string'],
                'body'      => ['type' => 'string', 'description' => 'Plain-text body.'],
                'cc'        => ['type' => 'string'],
                'thread_id' => ['type' => 'string', 'description' => 'Optional thread id to reply within.'],
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
            return ['error' => 'I need at least a recipient and a body.'];
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
            error_log('send_email: ' . $e->getMessage());
            return ['error' => 'I could not prepare that email just now.'];
        }

        $canSend = $this->email->sendEnabled();

        return [
            'drafted'  => true,
            'awaiting_confirmation' => $canSend,
            'draft_id' => $res['draft_id'],
            '_render'  => [
                'kind'         => 'email_draft',
                'title'        => $canSend ? 'Ready to send' : 'Saved as draft (sending is off)',
                'from'         => $res['account_email'],
                'to'           => $to,
                'cc'           => $draft->cc,
                'subject'      => $draft->subject,
                'body'         => $body,
                'note'         => $canSend
                    ? 'Review it and tap Send when you\'re happy.'
                    : 'Sending is currently off, so I saved this to your Drafts to send yourself.',
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
