<?php

declare(strict_types=1);

namespace App\Email;

use App\Data\EmailAccounts;
use RuntimeException;

/**
 * Entry point for all email operations. Owns two responsibilities the providers
 * must not: (1) resolving which of a user's accounts to act on, and (2) the
 * global SEND LOCK — sending is refused unless EMAIL_SEND_ENABLED is truthy,
 * regardless of provider capability.
 */
final class EmailService
{
    public function __construct(
        private EmailAccounts $accounts,
        private string $googleClientId = '',
        private string $googleClientSecret = '',
        private string $msClientId = '',
        private string $msClientSecret = '',
    ) {
    }

    public static function fromEnv(?EmailAccounts $accounts = null): self
    {
        return new self(
            $accounts ?? new EmailAccounts(),
            (string) ($_ENV['GOOGLE_CLIENT_ID'] ?? ''),
            (string) ($_ENV['GOOGLE_CLIENT_SECRET'] ?? ''),
            (string) ($_ENV['MS_CLIENT_ID'] ?? ''),
            (string) ($_ENV['MS_CLIENT_SECRET'] ?? ''),
        );
    }

    /** Whether actually sending mail is currently permitted (default: locked). */
    public function sendEnabled(): bool
    {
        $v = strtolower((string) ($_ENV['EMAIL_SEND_ENABLED'] ?? ''));

        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** True if the user has at least one active mailbox connected. */
    public function hasAccounts(int $userId): bool
    {
        return $this->accounts->listForUser($userId) !== [];
    }

    /**
     * @return array<int, array{id:int, provider:string, email:string, display_name:?string, status:string}>
     */
    public function accountsFor(int $userId): array
    {
        return $this->accounts->listForUser($userId);
    }

    /**
     * Resolve a loose account hint (an id as string, an email/substring, or a
     * provider name like "gmail") to a concrete account id, or null for "let the
     * default apply". Never throws — an unmatched hint falls through to default.
     */
    public function matchAccount(int $userId, ?string $hint): ?int
    {
        $hint = trim((string) $hint);
        if ($hint === '') {
            return null;
        }
        if (ctype_digit($hint)) {
            return (int) $hint;
        }
        $needle = mb_strtolower($hint);
        // Common spoken aliases → stored provider.
        $aliases = [
            'gmail' => 'gmail', 'google' => 'gmail',
            'outlook' => 'outlook', 'hotmail' => 'outlook', 'live' => 'outlook',
            'microsoft' => 'outlook', 'msn' => 'outlook',
        ];
        $providerHint = $aliases[$needle] ?? null;

        foreach ($this->accounts->listForUser($userId) as $a) {
            if (mb_strtolower($a['email']) === $needle
                || str_contains(mb_strtolower($a['email']), $needle)
                || $a['provider'] === $needle
                || $a['provider'] === $providerHint
                || ($a['display_name'] !== null && str_contains(mb_strtolower($a['display_name']), $needle))
            ) {
                return $a['id'];
            }
        }

        return null;
    }

    /**
     * @return array<int, EmailMessage>
     */
    public function listRecent(int $userId, ?int $accountId, int $limit = 15, ?string $query = null): array
    {
        [$account, $provider] = $this->resolve($userId, $accountId);

        return $provider->listRecent($limit, $query);
    }

    public function get(int $userId, ?int $accountId, string $messageId): ?EmailMessage
    {
        [$account, $provider] = $this->resolve($userId, $accountId);

        return $provider->get($messageId);
    }

    /** @return array{account_id:int, account_email:string, draft_id:string} */
    public function createDraft(int $userId, ?int $accountId, EmailDraft $draft): array
    {
        [$account, $provider] = $this->resolve($userId, $accountId);
        $draftId = $provider->createDraft($draft);

        return ['account_id' => $account['id'], 'account_email' => $account['email'], 'draft_id' => $draftId];
    }

    /**
     * Send a draft the user has confirmed (via the Send button). Enforces the
     * send lock. Returns the account id and provider message id/marker.
     *
     * @return array{account_id:int, message_id:string}
     */
    public function sendDraft(int $userId, ?int $accountId, string $draftId, EmailDraft $draft): array
    {
        if (!$this->sendEnabled()) {
            throw new SendLockedException('Sending email is currently turned off.');
        }
        [$account, $provider] = $this->resolve($userId, $accountId);
        $messageId = $provider->sendDraft($draftId, $draft);

        return ['account_id' => $account['id'], 'message_id' => $messageId];
    }

    /**
     * Send — refused unless the send lock is open. Throws SendLockedException so
     * the tool can present a clear, non-error message.
     *
     * @return array{account_id:int, message_id:string}
     */
    public function send(int $userId, ?int $accountId, EmailDraft $draft): array
    {
        if (!$this->sendEnabled()) {
            throw new SendLockedException('Sending email is currently locked. I saved it as a draft instead.');
        }
        [$account, $provider] = $this->resolve($userId, $accountId);
        $messageId = $provider->send($draft);

        return ['account_id' => $account['id'], 'message_id' => $messageId];
    }

    /**
     * Pick the account to act on: the given id if supplied and owned, otherwise
     * the user's first active account. Returns [publicMeta, provider].
     *
     * @return array{0: array{id:int, provider:string, email:string, display_name:?string, status:string}, 1: EmailProvider}
     */
    private function resolve(int $userId, ?int $accountId): array
    {
        if ($accountId !== null) {
            $meta = $this->accounts->get($userId, $accountId);
            if ($meta === null || $meta['status'] !== 'active') {
                throw new RuntimeException('That email account is not available.');
            }
        } else {
            $all = $this->accounts->listForUser($userId);
            if ($all === []) {
                throw new RuntimeException('No email account is connected yet.');
            }
            $meta = $all[0];
        }

        return [$meta, $this->providerFor($userId, $meta)];
    }

    /**
     * @param array{id:int, provider:string, email:string, display_name:?string, status:string} $meta
     */
    private function providerFor(int $userId, array $meta): EmailProvider
    {
        $creds = $this->accounts->credentials($userId, $meta['id']);
        if ($creds === null) {
            throw new RuntimeException('Missing credentials for that email account.');
        }

        return match ($meta['provider']) {
            'gmail' => new GmailProvider(
                $this->googleClientId,
                $this->googleClientSecret,
                (string) ($creds['refresh_token'] ?? ''),
                $meta['email'],
            ),
            'outlook' => new OutlookProvider(
                $this->msClientId,
                $this->msClientSecret,
                (string) ($creds['refresh_token'] ?? ''),
            ),
            'imap' => new ImapProvider($creds),
            default => throw new RuntimeException(
                'The ' . $meta['provider'] . ' provider is not wired up yet.'
            ),
        };
    }
}
