<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Home\HomeOwner;
use App\Home\HueSync;
use App\Home\ThermostatService;

/**
 * Tool: re-read the Hue lights, rooms, zones and scenes from the bridge and save the name -> id
 * list. The list also refreshes itself daily and whenever a list is fetched, so this is for "I just
 * added/renamed a light".
 */
final class RefreshHomeDevices implements Tool
{
    public function __construct(private HueSync $sync, private ApiTokens $tokens, private ?HomeOwner $owner = null, private ?ThermostatService $thermostats = null)
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
            . 'removed or renamed a light, or when a light you expected is missing. Also re-checks which radiator thermostats are paired '
            . '(the list the assistant keeps, fixed otherwise) — use it too when the user says a radiator was added, removed or renamed. Takes several seconds.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $userId = $this->owner?->resolve($userId)['owner_id'] ?? $userId;   // the shared home agent, if that's whose it is
        $ago = $this->tokens->secondsSinceUse($userId, AgentCommands::SCOPE);
        if ($ago === null || $ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
            return ['error' => 'The home agent (Pico) is offline, so I can\'t read the bridge right now. '
                . 'Use get_home_agent_setup for what to check.'];
        }

        $r = $this->sync->refresh($userId);
        $radiators = null;
        if ($this->thermostats !== null) {
            $v = $this->thermostats->rediscover($userId);
            $radiators = $v['error'] === null
                ? ['now' => array_map(static fn (array $t): string => $t['name'] . ' (' . $t['id'] . ')', $v['list']), 'changed' => $v['changed']]
                : ['error' => $v['error']];
        }

        return [
            'saved'  => $r['saved'],
            'failed' => $r['failed'],
            'radiators' => $radiators,
            'note'   => $r['failed'] === []
                ? 'The saved list is up to date.'
                : 'Could not read: ' . implode(', ', $r['failed']) . ' (a very long list can be cut off on the way back).',
        ];
    }
}
