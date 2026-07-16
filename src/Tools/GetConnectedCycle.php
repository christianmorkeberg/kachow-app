<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;
use App\Data\CycleTracker;

/**
 * Tool: read a connected person's cycle status — only if they share their 'cycle'
 * scope with the acting user. The permission check (accepted connection + 'cycle'
 * scope) is the controlled hole in the otherwise strict per-user scoping. Read-only.
 */
final class GetConnectedCycle implements Tool
{
    public function __construct(
        private Connections $connections,
        private CycleTracker $cycle,
    ) {
    }

    public function name(): string
    {
        return 'get_connected_cycle';
    }

    public function description(): string
    {
        return 'Gets the cycle status (current phase, predicted next period) of a person you are '
            . 'connected with, but ONLY if they share their cycle with you. Identify them by email or '
            . 'name (see list_connections). Use for "when is Anna\'s next period?", Danish "hvornår '
            . 'kommer Annas næste menstruation?". Read-only. Frame the fertile window as an estimate.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'person' => ['type' => 'string', 'description' => 'Email or name of the connected person.'],
            ],
            'required' => ['person'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $access = ConnectionAccess::resolve($this->connections, $userId, (string) ($arguments['person'] ?? ''), 'cycle');
        if (isset($access['error'])) {
            return $access;
        }

        $ownerId = (int) $access['owner_id'];
        $name    = isset($access['person']['name']) ? (string) $access['person']['name'] : null;
        $card    = $this->cycle->card($ownerId, ['name' => $name]);

        if (empty($card['has_data'])) {
            return ['person' => $access['person'], 'has_data' => false, 'message' => 'They have not logged any periods yet.', '_render' => $card];
        }

        return [
            'person'      => $access['person'],
            'phase'       => $card['phase_label'],
            'next_period' => $card['next_period'],
            'days_until'  => $card['days_until'],
            'in_fertile'  => $card['in_fertile'],
            'disclaimer'  => 'Estimate for planning only — not a reliable form of contraception.',
            '_render'     => $card,
        ];
    }
}
