<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Home\HueSync;

/**
 * Tool: re-read the Hue lights, rooms, zones and scenes from the bridge and save the name -> id
 * list. The list also refreshes itself daily and whenever a list is fetched, so this is for "I just
 * added/renamed a light".
 */
final class RefreshHomeDevices implements Tool
{
    public function __construct(private HueSync $sync, private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'refresh_home_devices';
    }

    public function description(): string
    {
        return 'Re-reads the user\'s Hue lights, rooms, zones and scenes from the bridge (through the home '
            . 'agent) and saves their names and ids, so later requests like "turn off the Le Klint" go straight '
            . 'through. The list already refreshes daily — use this when the user says they just added, '
            . 'removed or renamed a light, or when a light you expected is missing. Takes several seconds.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $ago = $this->tokens->secondsSinceUse($userId, AgentCommands::SCOPE);
        if ($ago === null || $ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
            return ['error' => 'The home agent (Pico) is offline, so I can\'t read the bridge right now. '
                . 'Use get_home_agent_setup for what to check.'];
        }

        $r = $this->sync->refresh($userId);

        return [
            'saved'  => $r['saved'],
            'failed' => $r['failed'],
            'note'   => $r['failed'] === []
                ? 'The saved list is up to date.'
                : 'Could not read: ' . implode(', ', $r['failed']) . ' (a very long list can be cut off on the way back).',
        ];
    }
}
