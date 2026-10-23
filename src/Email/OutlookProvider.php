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

    /**
     * The Graph request for a list/search. The tools phrase "unread only" Gmail-style (`is:unread`), which Graph
     * does not understand inside $search — so it is pulled out of the text and applied as a real filter:
     *   - unread, no text  → inbox with $filter=isRead eq false (newest first);
     *   - text (± unread)  → $search (relevance order; $search can't be combined with $filter/$orderby), and for
     *                        unread the page is over-fetched and filtered afterwards ('after_unread').
     *
     * @return array{path:string, after_unread:bool, fallback:?string}
     */
    public static function listRequest(int $limit, ?string $query): array
    {
        $limit  = max(1, min($limit, 25));
        $select = 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,isRead';
        $text   = trim((string) $query);
        $unread = false;
        if ($text !== '' && preg_match('/(?<![\w:])is:unread(?![\w])/i', $text) === 1) {
            $unread = true;
            $text   = trim((string) preg_replace('/(?<![\w:])is:unread(?![\w])/i', '', $text));
        }
        $base   = '$select=' . rawurlencode($select);
        $newest = '$orderby=' . rawurlencode('receivedDateTime desc');

        if ($text !== '') {
            $top = $unread ? min(50, $limit * 4) : $limit;

            return [
                'path' => '/me/messages?' . $base . '&$top=' . $top . '&$search=' . rawurlencode('"' . $text . '"'),
                'after_unread' => $unread,
                'fallback' => null,
            ];
        }
        if ($unread) {
            // Graph wants the $orderby property to appear in $filter too (else "InefficientFilter").
            $filter = '$filter=' . rawurlencode('receivedDateTime ge 1900-01-01T00:00:00Z and isRead eq false');

            return [
                'path' => '/me/mailFolders/inbox/messages?' . $base . '&$top=' . $limit . '&' . $filter . '&' . $newest,
                'after_unread' => false,
                // If Graph still refuses the combined filter: newest inbox page, filtered here.
                'fallback' => '/me/mailFolders/inbox/messages?' . $base . '&$top=' . min(50, $limit * 4) . '&' . $newest,
            ];
        }

        return ['path' => '/me/mailFolders/inbox/messages?' . $base . '&$top=' . $limit . '&' . $newest, 'after_unread' => false, 'fallback' => null];
    }

    public function listRecent(int $limit = 15, ?string $query = null): array
    {
        $limit = max(1, min($limit, 25));
        $req   = self::listRequest($limit, $query);

        try {
            $res = MsGraph::request($this->accessToken, 'GET', $req['path']);
            $fallbackUsed = false;
        } catch (\Throwable $e) {
            if ($req['fallback'] === null) {
                throw $e;
            }
            error_log('outlook unread filter refused, filtering client-side: ' . $e->getMessage());
            $res          = MsGraph::request($this->accessToken, 'GET', $req['fallback']);
            $fallbackUsed = true;
        }

        $out = [];
        foreach ($res['value'] ?? [] as $m) {
            if (($req['after_unread'] || $fallbackUsed) && ($m['isRead'] ?? true)) {
                continue;
            }
            $out[] = $this->summaryFrom($m);
        }

        return array_slice($out, 0, $limit);
    }

    public function get(string $messageId): ?EmailMessage
    {
        $select = 'id,conversationId,subject,from,toRecipients,receivedDateTime,bodyPreview,isRead,body,hasAttachments';
        try {
            // Ask for HTML so we can render it richly; we derive the plain text from it.
            $m = MsGraph::request(
                $this->accessToken,
                'GET',
                '/me/messages/' . rawurlencode($messageId) . '?$select=' . rawurlencode($select),
                null,
                ['Prefer: outlook.body-content-type="html"'],
            );
        } catch (\Throwable) {
            return null;
        }
        if (($m['id'] ?? null) === null) {
            return null;
        }

        $summary  = $this->summaryFrom($m);
        $content  = (string) ($m['body']['content'] ?? '');
        $isHtml   = ($m['body']['contentType'] ?? '') === 'html';
        $html     = $isHtml ? $content : '';
        $body     = $isHtml
            ? trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : trim($content);

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
            bodyHtml: $html,
            attachments: !empty($m['hasAttachments']) ? $this->attachmentList($messageId) : [],
        );
    }

    /** @return list<array{id:string, filename:string, mime:string, size:int}> */
    private function attachmentList(string $messageId): array
    {
        try {
            $r = MsGraph::request($this->accessToken, 'GET', '/me/messages/' . rawurlencode($messageId) . '/attachments?$select=id,name,contentType,size,isInline');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) ($r['value'] ?? []) as $a) {
            if (($a['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || !empty($a['isInline'])) {
                continue;
            }
            $out[] = ['id' => (string) $a['id'], 'filename' => (string) ($a['name'] ?? 'attachment'), 'mime' => (string) ($a['contentType'] ?? ''), 'size' => (int) ($a['size'] ?? 0)];
        }

        return $out;
    }

    public function attachment(string $messageId, string $attachmentId): ?array
    {
        try {
            $a = MsGraph::request($this->accessToken, 'GET', '/me/messages/' . rawurlencode($messageId) . '/attachments/' . rawurlencode($attachmentId));
        } catch (\Throwable) {
            return null;
        }
        $bytes = base64_decode((string) ($a['contentBytes'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return ['filename' => (string) ($a['name'] ?? 'attachment'), 'mime' => (string) ($a['contentType'] ?? ''), 'data' => $bytes];
    }

    public function markRead(string $messageId): void
    {
        MsGraph::request(
            $this->accessToken,
            'PATCH',
            '/me/messages/' . rawurlencode($messageId),
            ['isRead' => true],
        );
    }

    public function markAllRead(int $limit = 100): int
    {
        $path = '/me/mailFolders/inbox/messages?$filter=' . rawurlencode('isRead eq false')
            . '&$select=id&$top=' . max(1, min($limit, 100));
        $res   = MsGraph::request($this->accessToken, 'GET', $path);
        $count = 0;
        foreach ($res['value'] ?? [] as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $this->markRead($id);
                $count++;
            } catch (\Throwable $e) {
                error_log('OutlookProvider markAllRead: ' . $e->getMessage());
            }
        }

        return $count;
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
        if ($draft->attachments !== []) {
            $msg['attachments'] = array_map(static fn (array $a): array => [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => Mime::safeName($a['filename']),
                'contentType'  => $a['mime'],
                'contentBytes' => base64_encode($a['data']),
            ], $draft->attachments);
        }

        return $msg;
    }
}
