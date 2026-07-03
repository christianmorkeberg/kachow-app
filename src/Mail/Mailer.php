<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Sends transactional email. Abstracted so the transport (PHP mail(), SMTP, an
 * API, …) can be swapped without touching callers, and faked in tests.
 */
interface Mailer
{
    /**
     * Sends an HTML email. Returns true if the transport accepted it (not a
     * delivery guarantee).
     */
    public function send(string $to, string $subject, string $htmlBody): bool;
}
