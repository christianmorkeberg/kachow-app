<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\ThermostatService;
use App\Support\Thermostats;

/**
 * Tool: what the radiator thermostats say — the temperature they measure, the target, how open the valve is, the battery.
 */
final class GetThermostats implements Tool
{
    private ThermostatService $service;

    public function __construct(Tool $home, private UserSettings $settings, ?HomeState $state = null)
    {
        $this->service = new ThermostatService($home, $settings, $state);
    }

    public function name(): string
    {
        return 'get_thermostats';
    }

    public function description(): string
    {
        return 'Reads the user\'s radiator thermostats: current temperature, target, how open the valve is (%), battery. Use for "how warm is it", '
            . '"what is the heating set to", Danish "hvad er varmen sat til". Pass `thermostat` for one, or leave it out for all. With no names saved it asks the gateway which valves are paired, so it shows every valve — name them with update_setting `thermostats` ("Living room=200, Bedroom=201") once the user says which is which. Report only the numbers '
            . 'returned. current_c is measured at the valve, which sits on the radiator, so it reads warmer than the room. target_c 4 means the valve is at its minimum (heating off). last_heard_minutes_ago says how fresh the reading is: valves report every 5 minutes, so a big number means the reading is old — say so. '
            . 'The answer comes from a cache refreshed every 5 minutes (cached_minutes_ago says how old); pass fresh=true only when the user insists on a live reading (it asks every valve and takes a long time).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'thermostat' => ['type' => 'string', 'description' => 'Name or id of one thermostat; omit for all.'],
                'fresh'      => ['type' => 'boolean', 'description' => 'Read the valves live instead of the 5-minute cache (slow: about 7 s per valve).'],
            ],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $read = $this->service->rows($userId, !empty($arguments['fresh']));
        if ($read['error'] !== null) {
            return ['error' => $read['error']];
        }
        $all   = array_map(static fn (array $r): array => ['name' => (string) ($r['name'] ?? ''), 'id' => (int) ($r['id'] ?? 0)], $read['rows']);
        $query = trim((string) ($arguments['thermostat'] ?? ''));
        $rows  = $read['rows'];
        if ($query !== '') {
            $one = Thermostats::resolve($all, $query);
            if ($one === null) {
                return ['error' => 'No thermostat matches "' . $query . '". They are: ' . implode(', ', array_map(static fn (array $x): string => $x['name'], $all)) . '.'];
            }
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['id'] ?? 0) === $one['id']));
        }
        // A valve that could not be read is reported as such (the others still count), not as a failure of the whole call.
        $bad = array_values(array_filter($rows, static fn (array $r): bool => isset($r['error'])));
        if ($bad !== [] && count($bad) === count($rows)) {
            return ['error' => (string) $bad[0]['error']];
        }

        return ['thermostats' => $rows];
    }
}
