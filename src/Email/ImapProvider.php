<?php

declare(strict_types=1);

namespace App\Email;

use DateTimeImmutable;
use DateTimeZone;

/**
 * IMAP-backed mailbox (works for Outlook/Hotmail via app password, the dedicated
 * Kachow mailbox, or any standard IMAP host). Uses the self-contained ImapClient.
 *
 * Read + draft (APPEND to the Drafts folder). send() would need SMTP, which is
 * not wired yet — but sending is locked upstream anyway, so it is never reached.
 */
final class ImapProvider implements EmailProvider
{
    private ImapClient $client;
    private string $draftFolder;
    /** @var array<string, mixed> */
    private array $creds;

    /** @param array<string, mixed> $creds host/port/ssl/username/password/draft_folder[/smtp_*] */
    public function __construct(array $creds)
    {
        $this->creds  = $creds;
        $this->client = new ImapClient(
            (string) ($creds['host'] ?? ''),
            (int) ($creds['port'] ?? 993),
            20,
            ($creds['ssl'] ?? true) !== false,
        );
        $this->client->login((string) ($creds['username'] ?? ''), (string) ($creds['password'] ?? ''));
        $this->draftFolder = (string) ($creds['draft_folder'] ?? 'Drafts');
    }

    public function listRecent(int $limit = 15, ?string $query = null): array
    {
        $limit = max(1, min($limit, 25));
        $count = $this->client->select('INBOX');
        if ($count === 0) {
            return [];
        }

        // Translate the Gmail-ish query the tools build into IMAP search criteria.
        $unread = false;
        $text   = null;
        if ($query !== null && trim($query) !== '') {
            $q = trim($query);
            if (stripos($q, 'unread') !== false || stripos($q, 'unseen') !== false) {
                $unread = true;
                $q = trim(str_ireplace(['is:unread', 'unread', 'unseen'], '', $q));
            }
            $text = $q !== '' ? $q : null;
        }

        if ($unread || $text !== null) {
            $criteria = $unread ? 'UNSEEN' : 'ALL';
            if ($text !== null) {
                $criteria .= ' TEXT ' . '"' . str_replace('"', '', $text) . '"';
            }
            $uids = $this->client->searchUids($criteria);
            $uids = array_slice($uids, -$limit);
            $out  = [];
            foreach (array_reverse($uids) as $uid) {
                $out[] = $this->summaryFromHeader($this->client->fetchHeader((int) $uid, true));
            }

            return $out;
        }

        // Plain recent inbox: the last N by sequence number, newest first.
        $out   = [];
        $start = max(1, $count - $limit + 1);
        for ($seq = $count; $seq >= $start; $seq--) {
            $out[] = $this->summaryFromHeader($this->client->fetchHeader($seq, false));
        }

        return $out;
    }

    public function get(string $messageId): ?EmailMessage
    {
        $uid = (int) $messageId;
        if ($uid <= 0) {
            return null;
        }
        $this->client->select('INBOX');
        $raw = $this->client->fetchRaw($uid);
        if ($raw === '') {
            return null;
        }

        [$headerText, $bodyRaw] = $this->splitHeaders($raw);
        $headers = $this->parseHeaders($headerText);
        $body    = $this->extractText($headerText, $bodyRaw);

        return new EmailMessage(
            id:       $messageId,
            threadId: $messageId,
            from:     $this->decode($headers['from'] ?? ''),
            to:       $this->decode($headers['to'] ?? ''),
            subject:  $this->decode($headers['subject'] ?? '(no subject)'),
            snippet:  mb_substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 160),
            date:     $this->normalizeDate($headers['date'] ?? ''),
            unread:   false,
            bodyText: trim($body),
        );
    }

    public function createDraft(EmailDraft $draft): string
    {
        $this->client->append($this->draftFolder, $this->rawFor($draft));

        return 'draft';
    }

    public function send(EmailDraft $draft): string
    {
        // Send over SMTP (IMAP has no send). SMTP host/port default sensibly from
        // the IMAP settings (cPanel uses the same host on 465 SSL).
        $username = (string) ($this->creds['username'] ?? '');
        $host     = (string) ($this->creds['smtp_host'] ?? $this->creds['host'] ?? '');
        $port     = (int) ($this->creds['smtp_port'] ?? 465);
        $secure   = (string) ($this->creds['smtp_secure'] ?? ($port === 587 ? 'tls' : 'ssl'));

        $smtp = new SmtpClient($host, $port, $secure);
        try {
            $smtp->login($username, (string) ($this->creds['password'] ?? ''));
            $smtp->send($username, $this->addresses($draft), $this->rawFor($draft));
        } finally {
            $smtp->close();
        }

        return 'sent';
    }

    public function sendDraft(string $draftId, EmailDraft $draft): string
    {
        // IMAP can't send an existing draft; send the content over SMTP.
        return $this->send($draft);
    }

    /**
     * Bare recipient addresses (To + Cc) for the SMTP envelope.
     *
     * @return array<int, string>
     */
    private function addresses(EmailDraft $draft): array
    {
        $out = [];
        foreach (array_merge(explode(',', $draft->to), explode(',', $draft->cc)) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/<([^>]+)>/', $part, $m)) {
                $out[] = trim($m[1]);
            } else {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    // ---- helpers -----------------------------------------------------------

    /** @param array{uid:int, seen:bool, headers:string} $h */
    private function summaryFromHeader(array $h): EmailMessage
    {
        $headers = $this->parseHeaders($h['headers']);

        return new EmailMessage(
            id:       (string) $h['uid'],
            threadId: (string) $h['uid'],
            from:     $this->decode($headers['from'] ?? ''),
            to:       $this->decode($headers['to'] ?? ''),
            subject:  $this->decode($headers['subject'] ?? '(no subject)'),
            snippet:  '',
            date:     $this->normalizeDate($headers['date'] ?? ''),
            unread:   !$h['seen'],
        );
    }

    /** @return array{0:string, 1:string} [headerText, bodyRaw] */
    private function splitHeaders(string $raw): array
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            $pos = strpos($raw, "\n\n");
            if ($pos === false) {
                return [$raw, ''];
            }
            return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + 4)];
    }

    /** @return array<string, string> lower-cased header name => unfolded value */
    private function parseHeaders(string $headerText): array
    {
        $headerText = preg_replace('/\r?\n[ \t]+/', ' ', $headerText) ?? $headerText; // unfold
        $out        = [];
        foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s?(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return $out;
    }

    /** Recursively pull the best plain-text body out of a MIME message. */
    private function extractText(string $headerText, string $body): string
    {
        $headers  = $this->parseHeaders($headerText);
        $ctypeRaw = $headers['content-type'] ?? 'text/plain';   // original case (boundary is case-sensitive)
        $ctype    = strtolower($ctypeRaw);

        if (str_starts_with($ctype, 'multipart/') && preg_match('/boundary="?([^";]+)"?/i', $ctypeRaw, $b)) {
            $parts        = $this->splitMultipart($body, $b[1]);
            $htmlFallback = '';
            foreach ($parts as $part) {
                [$ph, $pb] = $this->splitHeaders($part);
                $pType     = strtolower($this->parseHeaders($ph)['content-type'] ?? '');
                $text      = $this->extractText($ph, $pb);
                if ($text !== '' && str_starts_with($pType, 'text/plain')) {
                    return $text;
                }
                if ($text !== '' && $htmlFallback === '') {
                    $htmlFallback = $text;
                }
            }
            return $htmlFallback;
        }

        $decoded = $this->decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''), $ctype);
        if (str_starts_with($ctype, 'text/html')) {
            return trim(html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return $decoded;
    }

    /** @return array<int, string> */
    private function splitMultipart(string $body, string $boundary): array
    {
        $chunks = preg_split('/--' . preg_quote($boundary, '/') . '(--)?\r?\n/', $body) ?: [];
        $out    = [];
        foreach ($chunks as $c) {
            if (trim($c) !== '' && trim($c) !== '--') {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function decodeBody(string $body, string $encoding, string $ctype): string
    {
        $body = match ($encoding) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };

        if (preg_match('/charset="?([^";\s]+)"?/i', $ctype, $m)) {
            $charset = strtoupper($m[1]);
            if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
                if ($converted !== false) {
                    $body = $converted;
                }
            }
        }

        return $body;
    }

    /** Decode RFC 2047 encoded-word header values (=?utf-8?...?=). */
    private function decode(string $value): string
    {
        $out = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $out !== false ? $out : $value;
    }

    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('c');
        } catch (\Throwable) {
            return $raw;
        }
    }

    /** Build a plain-text RFC 822 message for a draft. */
    private function rawFor(EmailDraft $draft): string
    {
        $safe = static fn (string $v): string => trim(str_replace(["\r", "\n"], '', $v));
        $enc  = static fn (string $v): string => preg_match('/[\x80-\xFF]/', $v)
            ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;

        $lines = [];
        $lines[] = 'To: ' . $safe($draft->to);
        if ($draft->cc !== '') {
            $lines[] = 'Cc: ' . $safe($draft->cc);
        }
        $lines[] = 'Subject: ' . $enc($safe($draft->subject));
        $lines[] = 'Date: ' . date('r');
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = chunk_split(base64_encode($draft->bodyText), 76, "\r\n");

        return implode("\r\n", $lines);
    }
}
