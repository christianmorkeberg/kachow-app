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
    /**
     * @param (\Closure(array):EmailProvider)|null $providerFactory builds a provider from an account's public
     *        meta — only for tests (a fake mailbox); production leaves it null and gets the real backends.
     */
    public function __construct(
        private EmailAccounts $accounts,
        private string $googleClientId = '',
        private string $googleClientSecret = '',
        private string $msClientId = '',
        private string $msClientSecret = '',
        private ?\Closure $providerFactory = null,
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

    /**
     * List or search across ALL of the user's active mailboxes (or only $onlyAccountId), newest first, each
     * message tagged with the mailbox it lives in. Report #33: without an account the old path looked at the
     * FIRST mailbox only, and the assistant then claimed to have searched all three. A mailbox that cannot be
     * reached is reported in `failed` (and logged) instead of failing the whole search.
     *
     * @return array{messages: list<array{message: EmailMessage, account: array{id:int, provider:string, email:string, display_name:?string, status:string}}>,
     *               searched: list<array{account:string, found:int}>, failed: list<array{account:string, error:string}>}
     */
    public function listAcross(int $userId, ?int $onlyAccountId, int $limit = 12, ?string $query = null): array
    {
        $accounts = $this->accounts->listForUser($userId);
        if ($onlyAccountId !== null) {
            $accounts = array_values(array_filter($accounts, static fn (array $a): bool => $a['id'] === $onlyAccountId));
        }
        if ($accounts === []) {
            throw new RuntimeException($onlyAccountId !== null ? 'That email account is not available.' : 'No email account is connected yet.');
        }

        $rows = $searched = $failed = [];
        foreach ($accounts as $meta) {
            try {
                $found = $this->providerFor($userId, $meta)->listRecent($limit, $query);
            } catch (\Throwable $e) {
                error_log('listAcross ' . $meta['provider'] . ': ' . $e->getMessage());
                $failed[] = ['account' => $meta['email'], 'error' => 'could not be reached'];
                continue;
            }
            $searched[] = ['account' => $meta['email'], 'found' => count($found)];
            foreach ($found as $m) {
                $rows[] = ['message' => $m, 'account' => $meta];
            }
        }
        if ($searched === []) {
            throw new RuntimeException('None of the mailboxes could be reached.');
        }

        // Newest first across mailboxes. (Outlook search comes back by relevance, Gmail by date — one order for all.)
        usort($rows, static fn (array $a, array $b): int => (strtotime($b['message']->date) ?: 0) <=> (strtotime($a['message']->date) ?: 0));

        return ['messages' => array_slice($rows, 0, max(1, $limit)), 'searched' => $searched, 'failed' => $failed];
    }

    /**
     * Finds one message when the caller doesn't know which mailbox it is in: tries each active mailbox in turn.
     * IMAP ids are plain numbers, so an IMAP mailbox is only asked for an all-digit id (a Gmail id that happens
     * to start with digits must never be read as an IMAP UID).
     *
     * @return array{account_id:int, message:EmailMessage}|null
     */
    public function locate(int $userId, string $messageId): ?array
    {
        foreach ($this->accounts->listForUser($userId) as $meta) {
            if ($meta['provider'] === 'imap' && !ctype_digit($messageId)) {
                continue;
            }
            try {
                $msg = $this->providerFor($userId, $meta)->get($messageId);
            } catch (\Throwable) {
                continue;
            }
            if ($msg !== null) {
                return ['account_id' => $meta['id'], 'message' => $msg];
            }
        }

        return null;
    }

    public function get(int $userId, ?int $accountId, string $messageId): ?EmailMessage
    {
        [$account, $provider] = $this->resolve($userId, $accountId);

        return $provider->get($messageId);
    }

    /**
     * One attachment's bytes. With no account named, the mailbox holding the message is found first (as read_email does).
     *
     * @return array{filename:string, mime:string, data:string}|null
     */
    public function attachment(int $userId, ?int $accountId, string $messageId, string $attachmentId): ?array
    {
        if ($accountId === null) {
            $found = $this->locate($userId, $messageId);
            if ($found === null) {
                return null;
            }
            $accountId = $found['account_id'];
        }
        [$account, $provider] = $this->resolve($userId, $accountId);

        return $provider->attachment($messageId, $attachmentId);
    }

    /** Mark a message read/seen. Best-effort; never critical to the caller. */
    public function markRead(int $userId, ?int $accountId, string $messageId): void
    {
        [$account, $provider] = $this->resolve($userId, $accountId);
        $provider->markRead($messageId);
    }

    /**
     * Mark specific messages read. Returns how many succeeded (per-id best-effort).
     *
     * @param array<int, string> $messageIds
     */
    public function markReadMany(int $userId, ?int $accountId, array $messageIds): int
    {
        [$account, $provider] = $this->resolve($userId, $accountId);
        $count = 0;
        foreach ($messageIds as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            try {
                $provider->markRead($id);
                $count++;
            } catch (\Throwable $e) {
                error_log('markReadMany: ' . $e->getMessage());
            }
        }

        return $count;
    }

    /** Mark all unread inbox messages read. Returns how many were marked. */
    public function markAllRead(int $userId, ?int $accountId, int $limit = 100): int
    {
        [$account, $provider] = $this->resolve($userId, $accountId);

        return $provider->markAllRead($limit);
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
        // Send the (possibly edited) content fresh, then remove the placeholder
        // draft so it doesn't linger in Drafts.
        $messageId = $provider->send($draft);
        if ($draftId !== '' && $draftId !== 'draft') {
            try {
                $provider->deleteDraft($draftId);
            } catch (\Throwable $e) {
                error_log('deleteDraft after send: ' . $e->getMessage());
            }
        }

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
        if ($this->providerFactory !== null) {
            return ($this->providerFactory)($meta);
        }
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
