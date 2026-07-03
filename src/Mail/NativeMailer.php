<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Mailer backed by PHP's built-in mail() (sendmail on cPanel). No dependencies.
 *
 * The From address should be on the app's own domain for SPF/DKIM alignment and
 * decent deliverability. Configure via MAIL_FROM (and optionally MAIL_FROM_NAME);
 * otherwise it derives no-reply@<request-host>.
 */
final class NativeMailer implements Mailer
{
    public function __construct(
        private string $fromEmail,
        private string $fromName = 'Kachow Assistant',
    ) {
    }

    public static function fromEnv(): self
    {
        $from = (string) ($_ENV['MAIL_FROM'] ?? '');
        if ($from === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $from = 'no-reply@' . preg_replace('/:\d+$/', '', $host);
        }

        return new self($from, (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Kachow Assistant'));
    }

    public function send(string $to, string $subject, string $htmlBody): bool
    {
        $encodedName    = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $encodedName . ' <' . $this->fromEmail . '>',
        ]);

        // -f sets the envelope sender, which helps deliverability. @ suppresses
        // warnings on hosts that restrict the 5th argument; we return the bool.
        return @mail($to, $encodedSubject, $htmlBody, $headers, '-f' . $this->fromEmail);
    }
}
