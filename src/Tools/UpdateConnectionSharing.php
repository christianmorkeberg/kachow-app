<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Tool: change what you share with a connection.
 */
final class UpdateConnectionSharing implements Tool
{
    public function __construct(private Connections $connections)
    {
    }

    public function name(): string
    {
        return 'update_connection_sharing';
    }

    public function description(): string
    {
        return 'Changes what YOU share with a connected person (by their email or name). Provide the '
            . 'full new list in "share" — any of: ' . Connections::scopeGuide() . '. It replaces your previous '
            . 'choice, so first look at list_connections (you_share) and keep what is already shared unless the '
            . 'user is removing it. "workouts_log" is a WRITE grant (e.g. a gym partner recording your sets), '
            . 'separate from "workouts" (which only lets them view yours, planned workouts included). "home" is '
            . 'also a grant to ACT: they control your home devices through your home agent, so they need no Pico '
            . 'of their own. Pass an empty list to stop sharing anything with them (without removing the '
            . 'connection). Only affects your side of the sharing.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => [
                    'type'        => 'string',
                    'description' => 'Email or name of the connected person.',
                ],
                'share' => [
                    'type'        => 'array',
                    'description' => 'The full set you want to share with them now: any of '
                        . Connections::scopeList() . '. Empty to share nothing.',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['person', 'share'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $person = trim((string) ($arguments['person'] ?? ''));
        if ($person === '') {
            return ['error' => 'Specify the connected person (email or name).'];
        }

        $entry = $this->connections->resolveByOther($userId, $person);
        if ($entry === null) {
            return ['error' => 'No connection found with that person.'];
        }

        $share  = is_array($arguments['share'] ?? null) ? $arguments['share'] : [];
        $scopes = Connections::normalizeScopes($share);

        $ok = $this->connections->updateScopes($userId, (int) $entry['connection_id'], $scopes);

        return $ok
            ? ['updated' => true, 'person' => $entry['person'], 'you_now_share' => Connections::scopesToArray($scopes)]
            : ['error' => 'Could not update sharing.'];
    }
}
