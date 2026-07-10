<?php

declare(strict_types=1);

namespace App\Email;

use DateTimeImmutable;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft as GmailDraft;
use Google\Service\Gmail\Message as GmailMessage;
use RuntimeException;

/**
 * Gmail-backed mailbox. Constructed with the app's Google OAuth client
 * credentials plus this account's stored refresh token; mints a short-lived
 * access token per instance. Reuses the same google/apiclient library already
 * used for Calendar.
 *
 * Scopes requested at connect time are readonly + compose (drafts). Sending is
 * gated upstream by EmailService's lock — this class will happily send if asked,
 * so it must only be reached through the service.
 */
final class GmailProvider implements EmailProvider
{
    private Gmail $service;
    private string $fromAddress;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $refreshToken,
        string $fromAddress = '',
    ) {
        $this->fromAddress = $fromAddress;
        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new RuntimeException(
                'Gmail auth failed: ' . ($token['error_description'] ?? $token['error'])
            );
        }
        $this->service = new Gmail($client);
    }

    public function listRecent(int $limit = 15, ?string $query = null): array
    {
        $limit  = max(1, min($limit, 25));
        $params = ['maxResults' => $limit, 'labelIds' => 'INBOX'];
        if ($query !== null && trim($query) !== '') {
            $params['q'] = trim($query);
            unset($params['labelIds']); // a search spans the whole mailbox
        }

        $list = $this->service->users_messages->listUsersMessages('me', $params);
        $out  = [];
        foreach ($list->getMessages() ?? [] as $ref) {
            $msg = $this->service->users_messages->get('me', $ref->getId(), [
                'format'         => 'metadata',
                'metadataHeaders' => ['From', 'To', 'Subject', 'Date'],
            ]);
            $out[] = $this->summaryFrom($msg);
        }

        return $out;
    }

    public function get(string $messageId): ?EmailMessage
    {
        try {
            $msg = $this->service->users_messages->get('me', $messageId, ['format' => 'full']);
        } catch (\Throwable) {
            return null;
        }

        $summary = $this->summaryFrom($msg);
        $body    = $this->extractBody($msg->getPayload());

        return new EmailMessage(
            id:       $summary->id,
            threadId: $summary->threadId,
            from:     $summary->from,
            to:       $summary->to,
            subject:  $summary->subject,
            snippet:  $summary->snippet,
            date:     $summary->date,
            unread:   $summary->unread,
            bodyText: $body,
        );
    }

    public function createDraft(EmailDraft $draft): string
    {
        $message = new GmailMessage();
        $message->setRaw($this->rawFor($draft));
        if ($draft->threadId !== null && $draft->threadId !== '') {
            $message->setThreadId($draft->threadId);
        }

        $gDraft = new GmailDraft();
        $gDraft->setMessage($message);
        $created = $this->service->users_drafts->create('me', $gDraft);

        return (string) $created->getId();
    }

    public function send(EmailDraft $draft): string
    {
        $message = new GmailMessage();
        $message->setRaw($this->rawFor($draft));
        if ($draft->threadId !== null && $draft->threadId !== '') {
            $message->setThreadId($draft->threadId);
        }
        $sent = $this->service->users_messages->send('me', $message);

        return (string) $sent->getId();
    }

    public function deleteDraft(string $draftId): void
    {
        if ($draftId !== '' && $draftId !== 'draft') {
            $this->service->users_drafts->delete('me', $draftId);
        }
    }

    // ---- helpers -----------------------------------------------------------

    private function summaryFrom(GmailMessage $msg): EmailMessage
    {
        $headers = [];
        foreach ($msg->getPayload()?->getHeaders() ?? [] as $h) {
            $headers[strtolower($h->getName())] = (string) $h->getValue();
        }
        $labels = $msg->getLabelIds() ?? [];

        return new EmailMessage(
            id:       (string) $msg->getId(),
            threadId: (string) $msg->getThreadId(),
            from:     $headers['from'] ?? '',
            to:       $headers['to'] ?? '',
            subject:  $headers['subject'] ?? '(no subject)',
            snippet:  html_entity_decode((string) $msg->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            date:     $this->normalizeDate($headers['date'] ?? ''),
            unread:   in_array('UNREAD', $labels, true),
        );
    }

    /** Recursively pull the best plain-text body out of a Gmail payload. */
    private function extractBody(?\Google\Service\Gmail\MessagePart $part): string
    {
        if ($part === null) {
            return '';
        }
        $mime = (string) $part->getMimeType();
        $data = $part->getBody()?->getData();

        if ($mime === 'text/plain' && $data) {
            return trim($this->b64urlDecode($data));
        }

        // Prefer a nested text/plain; fall back to stripped text/html.
        $htmlFallback = '';
        foreach ($part->getParts() ?? [] as $child) {
            $childText = $this->extractBody($child);
            if ($childText !== '' && (string) $child->getMimeType() === 'text/plain') {
                return $childText;
            }
            if ($childText !== '' && $htmlFallback === '') {
                $htmlFallback = $childText;
            }
        }
        if ($htmlFallback === '' && $mime === 'text/html' && $data) {
            $htmlFallback = trim(html_entity_decode(strip_tags($this->b64urlDecode($data)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $htmlFallback;
    }

    /** Build a base64url RFC 2822 message for drafts/sends. */
    private function rawFor(EmailDraft $draft): string
    {
        $lines   = [];
        // Explicit From = this connected account, so Gmail sends from the real
        // account identity rather than any "send mail as" default (which would
        // spoof another domain and fail its DMARC).
        if ($this->fromAddress !== '') {
            $lines[] = 'From: ' . $this->headerSafe($this->fromAddress);
        }
        $lines[] = 'To: ' . $this->headerSafe($draft->to);
        if ($draft->cc !== '') {
            $lines[] = 'Cc: ' . $this->headerSafe($draft->cc);
        }
        $lines[] = 'Subject: ' . $this->encodeHeader($draft->subject);
        if ($draft->inReplyToId !== null && $draft->inReplyToId !== '') {
            // inReplyToId here carries the RFC Message-ID of the message replied to.
            $lines[] = 'In-Reply-To: ' . $this->headerSafe($draft->inReplyToId);
            $lines[] = 'References: ' . $this->headerSafe($draft->inReplyToId);
        }
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = chunk_split(base64_encode($draft->bodyText), 76, "\r\n");

        return $this->b64urlEncode(implode("\r\n", $lines));
    }

    private function normalizeDate(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function headerSafe(string $v): string
    {
        // Strip CR/LF to prevent header injection.
        return trim(str_replace(["\r", "\n"], '', $v));
    }

    private function encodeHeader(string $v): string
    {
        $v = $this->headerSafe($v);
        if (preg_match('/[\x80-\xFF]/', $v)) {
            return '=?UTF-8?B?' . base64_encode($v) . '?=';
        }

        return $v;
    }

    private function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), false);
    }
}
