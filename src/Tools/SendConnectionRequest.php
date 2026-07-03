<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\Users;

/**
 * Tool: send a connection request to another user by email.
 */
final class SendConnectionRequest implements Tool
{
    public function __construct(
        private Connections $connections,
        private Users $users,
    ) {
    }

    public function name(): string
    {
        return 'send_connection_request';
    }

    public function description(): string
    {
        return 'Sends a connection request to another existing user by their email, so you can share '
            . 'data with each other (e.g. workout stats). Specify what YOU want to share via "share" '
            . '(any of: workouts, wishlist, calendar). They must accept and choose what they share back. '
            . 'If the person has no account yet, use create_invite first (admin only). Defaults to '
            . 'sharing workouts if "share" is omitted.';
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

        return [
            'request_sent' => true,
            'to'           => $email,
            'you_share'    => Connections::scopesToArray($scopes),
        ];
    }
}
