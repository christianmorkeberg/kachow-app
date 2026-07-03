<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Tool: remove a connection or decline a pending request.
 */
final class RemoveConnection implements Tool
{
    public function __construct(private Connections $connections)
    {
    }

    public function name(): string
    {
        return 'remove_connection';
    }

    public function description(): string
    {
        return 'Removes a connection with someone, or declines/cancels a pending request (by their '
            . 'email or name). This deletes the sharing link in both directions. Use when the user '
            . 'wants to disconnect from, decline, or stop sharing entirely with someone.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => [
                    'type'        => 'string',
                    'description' => 'Email or name of the person to disconnect from.',
                ],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $person = trim((string) ($arguments['person'] ?? ''));
        if ($person === '') {
            return ['error' => 'Specify who to remove (email or name).'];
        }

        $entry = $this->connections->resolveByOther($userId, $person);
        if ($entry === null) {
            return ['error' => 'No connection or request found with that person.'];
        }

        $removed = $this->connections->remove($userId, (int) $entry['connection_id']);

        return ['removed' => $removed, 'person' => $entry['person']];
    }
}
