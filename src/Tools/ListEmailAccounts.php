<?php

declare(strict_types=1);

namespace App\Tools;

use App\Email\EmailService;

/**
 * Tool: list the user's connected mailboxes (id, provider, address). Helps the
 * model pick an account and tells the user how to connect one if none exist.
 */
final class ListEmailAccounts implements Tool
{
    public function __construct(private EmailService $email)
    {
    }

    public function name(): string
    {
        return 'list_email_accounts';
    }

    public function description(): string
    {
        return 'Lists the email mailboxes the user has connected (provider and address). '
            . 'Use when they ask which emails are connected, or before acting if you need to pick one.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $accounts = $this->email->accountsFor($userId);
        if ($accounts === []) {
            return [
                'count'    => 0,
                'accounts' => [],
                'message'  => 'No email accounts are connected. The user can connect one from the menu (Connect email).',
            ];
        }

        return [
            'count'         => count($accounts),
            'accounts'      => $accounts,
            'send_enabled'  => $this->email->sendEnabled(),
        ];
    }
}
