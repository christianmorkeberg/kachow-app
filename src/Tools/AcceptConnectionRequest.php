<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Tool: accept a pending incoming connection request.
 */
final class AcceptConnectionRequest implements Tool
{
    public function __construct(private Connections $connections)
    {
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

        return $ok
            ? ['accepted' => true, 'with' => $entry['person'], 'you_share' => Connections::scopesToArray($scopes)]
            : ['error' => 'Could not accept the request.'];
    }
}
