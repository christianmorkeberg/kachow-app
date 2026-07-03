<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Users;
use App\Mail\Mailer;

/**
 * Tool: accept a pending incoming connection request.
 */
final class AcceptConnectionRequest implements Tool
{
    public function __construct(
        private Connections $connections,
        private Users $users,
        private Mailer $mailer,
    ) {
    }

    public function name(): string
    {
        return 'accept_connection_request';
    }

    public function description(): string
    {
        return 'Accepts a pending incoming connection request. Identify the requester by their email '
            . 'or name (see list_connections). Specify what YOU want to share back via "share" (any of: '
            . 'workouts, wishlist, calendar; defaults to workouts). Only pending requests sent to you '
            . 'can be accepted.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'from' => [
                    'type'        => 'string',
                    'description' => 'Email or name of the person who sent you the request.',
                ],
                'share' => [
                    'type'        => 'array',
                    'description' => 'What you want to share back: any of "workouts", "wishlist", "calendar".',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['from'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $from = trim((string) ($arguments['from'] ?? ''));
        if ($from === '') {
            return ['error' => 'Specify who the request is from (email or name).'];
        }

        $entry = $this->connections->resolveByOther($userId, $from);
        if ($entry === null) {
            return ['error' => 'No request or connection found with that person.'];
        }
        if ($entry['status'] !== 'pending' || $entry['direction'] !== 'incoming') {
            return ['error' => 'There is no pending request from that person to accept.'];
        }

        $share  = is_array($arguments['share'] ?? null) ? $arguments['share'] : ['workouts'];
        $scopes = Connections::normalizeScopes($share);

        $ok = $this->connections->accept((int) $entry['connection_id'], $userId, $scopes);
        if (!$ok) {
            return ['error' => 'Could not accept the request.'];
        }

        // Notify the requester that their request was accepted (best-effort).
        $me       = $this->users->findById($userId);
        $myName   = trim((string) ($me['name'] ?? '')) ?: 'Someone';
        $notified = false;
        $toEmail  = (string) ($entry['person']['email'] ?? '');
        if ($toEmail !== '') {
            $notified = $this->mailer->send(
                $toEmail,
                $myName . ' accepted your connection request',
                $this->emailBody($myName)
            );
        }

        return [
            'accepted'  => true,
            'with'      => $entry['person'],
            'you_share' => Connections::scopesToArray($scopes),
            'notified'  => $notified,
        ];
    }

    private function emailBody(string $accepterName): string
    {
        $safeName = htmlspecialchars($accepterName, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($this->baseUrl(), ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:system-ui,Segoe UI,Roboto,sans-serif;max-width:480px;margin:auto">'
            . '<h2>⚡ Kachow</h2>'
            . "<p><strong>{$safeName}</strong> accepted your connection request — you're now connected.</p>"
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;background:#38bdf8;color:#05263a;'
            . 'padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700">Open Kachow</a></p>'
            . '<p style="color:#667">You can now ask about the data you each chose to share.</p>'
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
