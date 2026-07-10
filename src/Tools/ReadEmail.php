<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailService;

/**
 * Tool: fetch one email in full (with body) so the assistant can summarise or
 * answer questions about it. Id comes from get_emails.
 */
final class ReadEmail implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'read_email';
    }

    public function description(): string
    {
        return 'Fetches the full text of one email by id (from get_emails) so you can summarise it or '
            . 'answer questions about it. account is optional when the user has several mailboxes. Read-only.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'      => ['type' => 'string', 'description' => 'The email id from get_emails.'],
                'account' => ['type' => 'string', 'description' => 'Which mailbox (email or provider), if several.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = trim((string) ($arguments['id'] ?? ''));
        if ($id === '') {
            return ['error' => 'An email id is required (get it from get_emails).'];
        }
        if (!$this->email->hasAccounts($userId)) {
            return ['connected' => false, 'error' => 'No email account is connected yet.'];
        }

        $accountId = $this->email->matchAccount($userId, isset($arguments['account']) ? (string) $arguments['account'] : null);

        try {
            $msg = $this->email->get($userId, $accountId, $id);
        } catch (\Throwable $e) {
            error_log('read_email: ' . $e->getMessage());
            return ['error' => 'I could not open that email just now.'];
        }
        if ($msg === null) {
            return ['error' => 'I could not find that email.'];
        }

        $data = $msg->toArray(true);

        return [
            'email'   => $data,
            '_render' => ['kind' => 'email', 'account_id' => $accountId] + $data,
        ];
    }
}
