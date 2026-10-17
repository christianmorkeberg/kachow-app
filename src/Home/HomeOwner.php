<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\Connections;

/**
 * Whose home agent (Pico) a user talks to. One Pico serves a household: the person who set it up
 * grants the "home" sharing scope to a connected person (update_connection_sharing), and that
 * person's home commands then ride the owner's agent, device map and saved light list instead
 * of needing a Pico of their own.
 *
 * A user who has an agent of their own always uses it. Otherwise the first accepted connection
 * that shares "home" AND has an agent wins. With neither, the user is their own "owner" and the
 * usual "the home agent has never connected" answer applies.
 */
final class HomeOwner
{
    public const SCOPE = 'home';

    public function __construct(private ApiTokens $tokens, private Connections $connections)
    {
    }

    /**
     * @return array{owner_id: int, shared: bool, name: ?string} shared = the agent belongs to someone else
     */
    public function resolve(int $userId): array
    {
        if ($this->tokens->has($userId, AgentCommands::SCOPE)) {
            return ['owner_id' => $userId, 'shared' => false, 'name' => null];
        }
        foreach ($this->connections->listForUser($userId) as $c) {
            if (($c['status'] ?? '') !== 'accepted' || !in_array(self::SCOPE, $c['they_share'] ?? [], true)) {
                continue;
            }
            $ownerId = (int) $c['person']['id'];
            if ($this->tokens->has($ownerId, AgentCommands::SCOPE)) {
                return ['owner_id' => $ownerId, 'shared' => true, 'name' => (string) ($c['person']['name'] ?? '') ?: null];
            }
        }

        return ['owner_id' => $userId, 'shared' => false, 'name' => null];
    }
}
