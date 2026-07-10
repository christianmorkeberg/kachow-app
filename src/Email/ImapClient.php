<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Minimal, self-contained IMAP client over a TLS socket — no ext-imap (removed
 * from core in PHP 8.4) and no third-party library. Implements just what the
 * assistant needs: login, select, search, fetch headers/raw, and append a draft.
 *
 * Commands are tagged; the response reader understands IMAP literals ({n}) so it
 * reads binary/multiline payloads correctly. One literal per FETCH command (we
 * fetch per-message) keeps parsing simple and robust.
 */
final class ImapClient
{
    /** @var resource */
    private $stream;
    private int $tag = 0;

    public function __construct(
        string $host,
        int $port = 993,
        int $timeout = 20,
        bool $ssl = true,
    ) {
        $target  = ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream  = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException('Could not connect to the mail server (' . $errstr . ').');
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;

        $greeting = fgets($this->stream);
        if ($greeting === false || !str_contains($greeting, 'OK')) {
            throw new RuntimeException('The mail server did not greet us as expected.');
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($pass));
    }

    /** Select a mailbox; returns the message count (EXISTS). */
    public function select(string $mailbox = 'INBOX'): int
    {
        $raw = $this->command('SELECT ' . $this->quote($mailbox));

        return preg_match('/(\d+) EXISTS/', $raw, $m) ? (int) $m[1] : 0;
    }

    /**
     * UID SEARCH; returns matching UIDs in server order.
     *
     * @return array<int, int>
     */
    public function searchUids(string $criteria): array
    {
        $raw = $this->command('UID SEARCH ' . $criteria);
        if (!preg_match('/^\* SEARCH([\d ]*)/mi', $raw, $m)) {
            return [];
        }

        return array_map('intval', array_values(array_filter(preg_split('/\s+/', trim($m[1])) ?: [], 'strlen')));
    }

    /**
     * Fetch the summary headers + flags for one message (by UID or sequence no.).
     *
     * @return array{uid:int, seen:bool, headers:string}
     */
    public function fetchHeader(int $id, bool $byUid = true): array
    {
        $raw = $this->command(
            ($byUid ? 'UID ' : '') . 'FETCH ' . $id
            . ' (UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE)])'
        );

        return [
            'uid'     => preg_match('/UID (\d+)/', $raw, $m) ? (int) $m[1] : $id,
            'seen'    => (bool) preg_match('/\\\\Seen/i', (string) (preg_match('/FLAGS \(([^)]*)\)/', $raw, $f) ? $f[1] : '')),
            'headers' => $this->firstLiteral($raw),
        ];
    }

    /** Fetch a whole raw RFC822 message by UID. */
    public function fetchRaw(int $uid): string
    {
        $raw = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');

        return $this->firstLiteral($raw);
    }

    /** Append a raw message to a folder with the \Draft flag. */
    public function append(string $folder, string $rawMessage): void
    {
        $rawMessage = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $rawMessage));
        $tag        = 'A' . (++$this->tag);
        $len        = strlen($rawMessage);

        $this->write($tag . ' APPEND ' . $this->quote($folder) . ' (\\Draft) {' . $len . "}\r\n");
        $cont = fgets($this->stream);
        if ($cont === false || ($cont[0] ?? '') !== '+') {
            throw new RuntimeException('The server did not accept the draft upload.');
        }
        $this->write($rawMessage . "\r\n");
        $this->readResponse($tag); // throws on NO/BAD
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            try {
                $this->write('A' . (++$this->tag) . " LOGOUT\r\n");
            } catch (\Throwable) {
                // ignore
            }
            fclose($this->stream);
        }
    }

    // ---- internals ---------------------------------------------------------

    private function command(string $command): string
    {
        $tag = 'A' . (++$this->tag);
        $this->write($tag . ' ' . $command . "\r\n");

        return $this->readResponse($tag);
    }

    /** Read until the tagged completion line; splices in IMAP literals verbatim. */
    private function readResponse(string $tag): string
    {
        $raw = '';
        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                throw new RuntimeException($meta['timed_out'] ? 'The mail server timed out.' : 'The mail connection dropped.');
            }
            $raw .= $line;

            if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $need = (int) $m[1];
                $got  = 0;
                while ($got < $need) {
                    $chunk = fread($this->stream, min(8192, $need - $got));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $raw .= $chunk;
                    $got += strlen($chunk);
                }
                continue;
            }

            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)\b ?(.*)$/i', rtrim($line), $mm)) {
                if (strtoupper($mm[1]) !== 'OK') {
                    throw new RuntimeException('Mail server rejected the request: ' . trim($mm[2]));
                }
                break;
            }
        }

        return $raw;
    }

    /** Extract the first {n}-literal payload from a response. */
    private function firstLiteral(string $raw): string
    {
        if (preg_match('/\{(\d+)\}\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $len   = (int) $m[1][0];
            $start = $m[0][1] + strlen($m[0][0]);

            return substr($raw, $start, $len);
        }

        return '';
    }

    private function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new RuntimeException('Failed writing to the mail server.');
        }
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
