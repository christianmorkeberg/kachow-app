<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;
use App\Home\ThermostatService;
use App\Support\Thermostats;

/**
 * Tool: what the radiator thermostats say — the temperature they measure, the target, how open the valve is, the battery.
 */
final class GetThermostats implements Tool
{
    private ThermostatService $service;

    public function __construct(Tool $home, private UserSettings $settings)
    {
        $this->service = new ThermostatService($home, $settings);
    }

    public function name(): string
    {
        return 'get_thermostats';
    }

    public function description(): string
    {
        return 'Reads the user\'s radiator thermostats: current temperature, target, how open the valve is (%), battery. Use for "how warm is it", '
            . '"what is the heating set to", Danish "hvad er varmen sat til". Pass `thermostat` for one, or leave it out for all. With no names saved it asks the gateway which valves are paired, so it shows every valve — name them with update_setting `thermostats` ("Living room=200, Bedroom=201") once the user says which is which. Report only the numbers '
            . 'returned. current_c is measured at the valve, which sits on the radiator, so it reads warmer than the room. target_c 4 means the valve is at its minimum (heating off). last_heard_minutes_ago says how fresh the reading is: valves report every few minutes, so a big number means the reading is old — say so.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['thermostat' => ['type' => 'string', 'description' => 'Name or id of one thermostat; omit for all.']],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $l = $this->service->list($userId);
        if ($l['error'] !== null) {
            return ['error' => $l['error']];
        }
        $all   = $l['list'];
        $query = trim((string) ($arguments['thermostat'] ?? ''));
        if ($query !== '') {
            $one = Thermostats::resolve($all, $query);
            if ($one === null) {
                return ['error' => 'No thermostat matches "' . $query . '". They are: ' . implode(', ', array_map(static fn (array $x): string => $x['name'], $all)) . '.'];
            }
            $all = [$one];
        }
        $out = [];
        foreach ($all as $t) {
            $r = $this->service->read($userId, $t);
            if (isset($r['error'])) {
                return ['error' => $r['error']];
            }
            $out[] = $r['row'];
        }

        return ['thermostats' => $out];
    }
}
