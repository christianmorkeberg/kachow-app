<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Users;
use App\Mail\Mailer;

/**
 * Tool: send a connection request to another user by email.
 */
final class SendConnectionRequest implements Tool
{
    public function __construct(
        private Connections $connections,
        private Users $users,
        private Mailer $mailer,
    ) {
    }

    public function name(): string
    {
        return 'send_connection_request';
    }

    public function description(): string
    {
        return 'Sends a connection request to another existing user by their email (and emails them a '
            . 'notification to sign in and accept), so you can share data with each other (e.g. workout '
            . 'stats). Specify what YOU want to share via "share" (any of: workouts, wishlist, calendar). '
            . 'They must accept and choose what they share back. If the person has no account yet, use '
            . 'create_invite first (admin only). Defaults to sharing workouts if "share" is omitted.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'email' => [
                    'type'        => 'string',
                    'description' => "The other person's account email.",
                ],
                'share' => [
                    'type'        => 'array',
                    'description' => 'What you want to share with them: any of "workouts", "wishlist", '
                        . '"calendar".',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['email'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $email = strtolower(trim((string) ($arguments['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'A valid email address is required.'];
        }

        $target = $this->users->findByEmail($email);
        if ($target === null) {
            return ['error' => 'No user has that email yet. They need an account first — invite them.'];
        }
        $targetId = (int) $target['id'];
        if ($targetId === $userId) {
            return ['error' => 'You cannot connect with yourself.'];
        }
        if ($this->connections->findBetween($userId, $targetId) !== null) {
            return ['error' => 'You already have a connection or pending request with that person.'];
        }

        $share  = is_array($arguments['share'] ?? null) ? $arguments['share'] : ['workouts'];
        $scopes = Connections::normalizeScopes($share);

        $this->connections->request($userId, $targetId, $scopes);

        // Notify the addressee by email (best-effort; the request stands regardless).
        $actor        = $this->users->findById($userId);
        $requesterName = trim((string) ($actor['name'] ?? '')) ?: 'Someone';
        $notified = $this->mailer->send(
            $email,
            $requesterName . ' wants to connect with you on Kachow',
            $this->emailBody($requesterName)
        );

        return [
            'request_sent' => true,
            'to'           => $email,
            'you_share'    => Connections::scopesToArray($scopes),
            'notified'     => $notified,
        ];
    }

    private function emailBody(string $requesterName): string
    {
        $safeName = htmlspecialchars($requesterName, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($this->baseUrl(), ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:480px;margin:auto">'
            . '<h2>⚡ Kachow</h2>'
            . "<p><strong>{$safeName}</strong> wants to connect with you on Kachow so you can share data "
            . '(like workout stats) with each other.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;background:#38bdf8;color:#05263a;'
            . 'padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700">Open Kachow</a></p>'
            . "<p style=\"color:#667\">Sign in and say &ldquo;accept {$safeName}&rsquo;s request&rdquo; to connect "
            . '(you choose what you share back).</p>'
            . '</div>';
    }

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
