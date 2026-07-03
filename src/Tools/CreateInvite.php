<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Invites;
use App\Data\Users;
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
    ) {
    }

    public function name(): string
    {
        return 'create_invite';
    }

    public function description(): string
    {
        return 'Creates a registration invite link so a new person can make their own account. '
            . 'ADMIN ONLY — returns an error if the current user is not an admin. Provide the '
            . "person's email; returns a link to share with them (valid " . self::EXPIRY_DAYS
            . ' days). Use when an admin asks to invite someone or add a new user.';
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

        return [
            'invited'         => true,
            'email'           => $email,
            'invite_url'      => $this->baseUrl() . '/register.php?token=' . $rawToken,
            'expires_in_days' => self::EXPIRY_DAYS,
        ];
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
