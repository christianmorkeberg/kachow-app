<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailDraft;
use App\Email\EmailService;
use App\Email\SendLockedException;

/**
 * Tool: send an email. Sending is LOCKED for now (EMAIL_SEND_ENABLED off): the
 * tool exists so the model can attempt it, but it falls back to saving a draft
 * and tells the user sending is disabled. When unlocked later, it sends for real.
 */
final class SendEmail implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'send_email';
    }

    public function description(): string
    {
        return 'Sends an email on the user\'s behalf. NOTE: sending is currently locked, so this will save '
            . 'the message as a draft and tell the user instead of sending. Prefer draft_email; only call this '
            . 'if the user explicitly asks to send. account is optional when several mailboxes exist.';
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
            return ['error' => 'I need at least a recipient and a body.'];
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
            $res = $this->email->send($userId, $accountId, $draft);

            return [
                'sent'       => true,
                'message_id' => $res['message_id'],
                '_render'    => ['kind' => 'email_draft', 'title' => 'Email sent', 'to' => $to,
                    'subject' => $draft->subject, 'body' => $body, 'sent' => true, 'note' => 'Sent.'],
            ];
        } catch (SendLockedException) {
            // Locked — fall back to a draft so the user's intent isn't lost.
            try {
                $this->email->createDraft($userId, $accountId, $draft);
            } catch (\Throwable $e) {
                error_log('send_email (draft fallback): ' . $e->getMessage());
            }

            return [
                'sent'      => false,
                'locked'    => true,
                'message'   => 'Sending email is turned off right now, so I saved it to your Drafts instead.',
                '_render'   => [
                    'kind'    => 'email_draft',
                    'title'   => 'Saved as draft (sending is off)',
                    'to'      => $to,
                    'cc'      => $draft->cc,
                    'subject' => $draft->subject,
                    'body'    => $body,
                    'note'    => 'Sending is currently locked. I saved this to your Drafts — send it yourself, '
                        . 'or unlock sending later.',
                    'sent'    => false,
                ],
            ];
        } catch (\Throwable $e) {
            error_log('send_email: ' . $e->getMessage());
            return ['error' => 'I could not send that just now.'];
        }
    }
}
