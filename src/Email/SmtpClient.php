<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Minimal, self-contained SMTP sender over a socket — no ext, no library. Used to
 * send from IMAP-configured mailboxes (e.g. the cPanel Kachow mailbox), where
 * IMAP itself can't send. Supports implicit TLS (port 465) and STARTTLS (587),
 * with AUTH LOGIN.
 */
final class SmtpClient
{
    /** @var resource */
    private $stream;

    /**
     * @param 'ssl'|'tls'|'none' $secure
     */
    public function __construct(
        private string $host,
        private int $port = 465,
        private string $secure = 'ssl',
        private int $timeout = 20,
    ) {
        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $context   = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream    = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new RuntimeException('Could not connect to the SMTP server (' . $errstr . ').');
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;

        $this->expect(220);
        $this->ehlo();
        if ($this->secure === 'tls') {
            $this->command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not start TLS with the SMTP server.');
            }
            $this->ehlo(); // re-EHLO after TLS
        }
    }

    public function login(string $user, string $pass): void
    {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($user), 334);
        $this->command(base64_encode($pass), 235);
    }

    /**
     * @param array<int, string> $recipients bare addresses
     */
    public function send(string $from, array $recipients, string $rawMessage): void
    {
        $this->command('MAIL FROM:<' . $from . '>', 250);
        foreach ($recipients as $rcpt) {
            if ($rcpt !== '') {
                $this->command('RCPT TO:<' . $rcpt . '>', 250);
            }
        }
        $this->command('DATA', 354);
        // Dot-stuffing + ensure CRLF line endings; terminate with <CRLF>.<CRLF>.
        $data = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $rawMessage)));
        $this->write($data . "\r\n.\r\n");
        $this->expect(250);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            try {
                $this->command('QUIT', 221);
            } catch (\Throwable) {
                // ignore
            }
            fclose($this->stream);
        }
    }

    // ---- internals ---------------------------------------------------------

    private function ehlo(): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->command('EHLO ' . $host, 250);
    }

    private function command(string $line, int $expect): void
    {
        $this->write($line . "\r\n");
        $this->expect($expect);
    }

    private function expect(int $code): void
    {
        // SMTP replies may span multiple lines ("250-..." then "250 ...").
        do {
            $line = fgets($this->stream);
            if ($line === false) {
                throw new RuntimeException('SMTP connection dropped.');
            }
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        $got = (int) substr($line, 0, 3);
        if ($got !== $code) {
            throw new RuntimeException('SMTP error: ' . trim($line));
        }
    }

    private function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new RuntimeException('Failed writing to the SMTP server.');
        }
    }
}
