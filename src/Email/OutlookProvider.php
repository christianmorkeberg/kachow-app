<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Outlook/Hotmail mailbox via Microsoft Graph (raw REST, no SDK). Constructed
 * with the app's MS OAuth credentials plus this account's stored refresh token;
 * mints a short-lived access token per instance.
 *
 * Read + draft only via Mail.ReadWrite. send() exists for interface parity but is
 * gated upstream by EmailService's lock (Mail.Send is not even granted yet).
 */
final class OutlookProvider implements EmailProvider
{
    private string $accessToken;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken)
    {
        $token = MsGraph::tokenFromRefresh($clientId, $clientSecret, $refreshToken);
        $access = (string) ($token['access_token'] ?? '');
        if ($access === '') {
            throw new RuntimeException('Outlook auth failed: no access token returned.');
        }
        $this->accessToken = $access;
    }

    public function listRecent(int $limit = 15, ?string $query = null): array
    {
        $limit  = max(1, min($limit, 25));
        $select = 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,isRead';

        if ($query !== null && trim($query) !== '') {
            // $search can't be combined with $orderby; Graph returns by relevance.
            $path = '/me/messages?$select=' . rawurlencode($select)
                . '&$top=' . $limit
                . '&$search=' . rawurlencode('"' . trim($query) . '"');
        } else {
            $path = '/me/mailFolders/inbox/messages?$select=' . rawurlencode($select)
                . '&$top=' . $limit
                . '&$orderby=' . rawurlencode('receivedDateTime desc');
        }

        $res = MsGraph::request($this->accessToken, 'GET', $path);
        $out = [];
        foreach ($res['value'] ?? [] as $m) {
            $out[] = $this->summaryFrom($m);
        }

        return $out;
    }

    public function get(string $messageId): ?EmailMessage
    {
        $select = 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,isRead,body';
        try {
            // Prefer plain text body over HTML.
            $m = MsGraph::request(
                $this->accessToken,
                'GET',
                '/me/messages/' . rawurlencode($messageId) . '?$select=' . rawurlencode($select),
                null,
                ['Prefer: outlook.body-content-type="text"'],
            );
        } catch (\Throwable) {
            return null;
        }
        if (($m['id'] ?? null) === null) {
            return null;
        }

        $summary = $this->summaryFrom($m);
        $body    = (string) ($m['body']['content'] ?? '');
        if (($m['body']['contentType'] ?? '') === 'html') {
            $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return new EmailMessage(
            id:       $summary->id,
            threadId: $summary->threadId,
            from:     $summary->from,
            to:       $summary->to,
            subject:  $summary->subject,
            snippet:  $summary->snippet,
            date:     $summary->date,
            unread:   $summary->unread,
            bodyText: trim($body),
        );
    }

    public function createDraft(EmailDraft $draft): string
    {
        $created = MsGraph::request($this->accessToken, 'POST', '/me/messages', $this->messageBody($draft));

        return (string) ($created['id'] ?? '');
    }

    public function send(EmailDraft $draft): string
    {
        // POST /me/sendMail returns 202 with no id; the message lands in Sent Items.
        MsGraph::request($this->accessToken, 'POST', '/me/sendMail', [
            'message'         => $this->messageBody($draft),
            'saveToSentItems' => true,
        ]);

        return 'sent';
    }

    public function deleteDraft(string $draftId): void
    {
        if ($draftId !== '' && $draftId !== 'draft') {
            MsGraph::request($this->accessToken, 'DELETE', '/me/messages/' . rawurlencode($draftId));
        }
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array<string, mixed> $m */
    private function summaryFrom(array $m): EmailMessage
    {
        $from = $this->addr($m['from'] ?? null);
        $to   = implode(', ', array_map(
            fn ($r) => $this->addr($r),
            is_array($m['toRecipients'] ?? null) ? $m['toRecipients'] : []
        ));

        return new EmailMessage(
            id:       (string) ($m['id'] ?? ''),
            threadId: (string) ($m['conversationId'] ?? ''),
            from:     $from,
            to:       $to,
            subject:  (string) ($m['subject'] ?? '(no subject)'),
            snippet:  (string) ($m['bodyPreview'] ?? ''),
            date:     (string) ($m['receivedDateTime'] ?? ''),
            unread:   !($m['isRead'] ?? true),
        );
    }

    /** Format a Graph recipient object as "Name <address>" (or just the address). */
    private function addr(mixed $r): string
    {
        $ea = is_array($r) ? ($r['emailAddress'] ?? null) : null;
        if (!is_array($ea)) {
            return '';
        }
        $name = trim((string) ($ea['name'] ?? ''));
        $mail = trim((string) ($ea['address'] ?? ''));
        if ($name !== '' && $name !== $mail) {
            return $name . ' <' . $mail . '>';
        }

        return $mail;
    }

    /** Build a Graph message resource from a draft (plain-text body). */
    private function messageBody(EmailDraft $draft): array
    {
        $recip = static fn (string $csv): array => array_values(array_filter(array_map(
            static fn (string $a) => trim($a) === '' ? null : ['emailAddress' => ['address' => trim($a)]],
            explode(',', $csv)
        )));

        $msg = [
            'subject'      => $draft->subject,
            'body'         => ['contentType' => 'Text', 'content' => $draft->bodyText],
            'toRecipients' => $recip($draft->to),
        ];
        if ($draft->cc !== '') {
            $msg['ccRecipients'] = $recip($draft->cc);
        }

        return $msg;
    }
}
