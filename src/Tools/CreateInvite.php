<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Invites;
use App\Data\Users;
use App\Mail\Mailer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: create a registration invite link (ADMIN ONLY).
 *
 * Gated server-side on the acting user's role, so it is safe even though the tool
 * is offered to every user's model — a non-admin call just returns an error.
 */
final class CreateInvite implements Tool
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private Users $users,
        private Invites $invites,
        private Mailer $mailer,
    ) {
    }

    public function name(): string
    {
        return 'create_invite';
    }

    public function description(): string
    {
        return 'Invites a new person to create their own account by emailing them a registration link. '
            . 'ADMIN ONLY — returns an error if the current user is not an admin. Provide the '
            . "person's email; the link is emailed to them (valid " . self::EXPIRY_DAYS . ' days) and '
            . 'also returned so the admin can share it manually if needed. The response "email_sent" '
            . 'indicates whether the email was accepted for delivery.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'email' => [
                    'type'        => 'string',
                    'description' => 'Email address of the person to invite.',
                ],
            ],
            'required' => ['email'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $actor = $this->users->findById($userId);
        if ($actor === null || ($actor['role'] ?? '') !== 'admin') {
            return ['error' => 'Only an admin can create invites.'];
        }

        $email = strtolower(trim((string) ($arguments['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'A valid email address is required.'];
        }
        if ($this->users->findByEmail($email) !== null) {
            return ['error' => 'That email already has an account.'];
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = new DateTimeImmutable('+' . self::EXPIRY_DAYS . ' days', new DateTimeZone('UTC'));

        $this->invites->create($email, $tokenHash, $userId, $expiresAt->format('Y-m-d H:i:s'));

        $inviteUrl = $this->baseUrl() . '/register.php?token=' . $rawToken;
        $inviterName = trim((string) ($actor['name'] ?? '')) ?: 'An admin';
        $emailSent = $this->mailer->send(
            $email,
            'You have been invited to Kachow',
            $this->emailBody($inviterName, $inviteUrl)
        );

        return [
            'invited'         => true,
            'email'           => $email,
            'email_sent'      => $emailSent,
            'invite_url'      => $inviteUrl,
            'expires_in_days' => self::EXPIRY_DAYS,
        ];
    }

    private function emailBody(string $inviterName, string $inviteUrl): string
    {
        $safeName = htmlspecialchars($inviterName, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:480px;margin:auto">'
            . '<h2>⚡ Kachow</h2>'
            . "<p>{$safeName} invited you to create a Kachow account.</p>"
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;background:#38bdf8;color:#05263a;'
            . 'padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700">Create your account</a></p>'
            . '<p style="color:#667">Or paste this link into your browser:<br>' . $safeUrl . '</p>'
            . '<p style="color:#889;font-size:13px">This link is valid for ' . self::EXPIRY_DAYS . ' days.</p>'
            . '</div>';
    }

    /**
     * Base URL for building the invite link: APP_BASE_URL if set, otherwise
     * derived from the current request host.
     */
    private function baseUrl(): string
    {
        $configured = $_ENV['APP_BASE_URL'] ?? '';
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

        return $scheme . '://' . $host;
    }
}
