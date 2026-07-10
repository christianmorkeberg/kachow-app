<?php

declare(strict_types=1);

namespace App\Email;

/**
 * One connected mailbox, abstracted over its backend (Gmail API, MS Graph, IMAP).
 * Implementations are constructed per-account with that account's decrypted
 * credentials and never touch the database.
 *
 * The send() method exists on the interface but callers must route sending
 * through EmailService, which enforces the global send lock — a provider must
 * never be handed a send request that the service has not cleared.
 */
interface EmailProvider
{
    /**
     * Recent messages (most recent first). $query is an optional provider-native
     * search string (e.g. Gmail "from:x is:unread"); null lists the inbox.
     *
     * @return array<int, EmailMessage>  summaries (bodyText empty)
     */
    public function listRecent(int $limit = 15, ?string $query = null): array;

    /** A single message with its plain-text body, or null if not found. */
    public function get(string $messageId): ?EmailMessage;

    /** Save a draft in the mailbox's Drafts folder; returns the provider draft id. */
    public function createDraft(EmailDraft $draft): string;

    /** Send a message; returns the provider message id. Gated by EmailService. */
    public function send(EmailDraft $draft): string;

    /**
     * Send a previously created draft. OAuth providers send the existing draft by
     * id (which also removes it from Drafts and keeps threading); IMAP falls back
     * to sending the given content over SMTP. Gated by EmailService.
     */
    public function sendDraft(string $draftId, EmailDraft $draft): string;
}
