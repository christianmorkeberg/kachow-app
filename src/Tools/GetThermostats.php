<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;
use App\Support\Thermostats;

/**
 * Tool: what the radiator thermostats say — the temperature they measure, the target, how open the valve is, the battery.
 */
final class GetThermostats implements Tool
{
    public function __construct(private Tool $home, private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'get_thermostats';
    }

    public function description(): string
    {
        return 'Reads the user\'s radiator thermostats: current temperature, target, how open the valve is (%), battery. Use for "how warm is it", '
            . '"what is the heating set to", Danish "hvad er varmen sat til". Pass `thermostat` for one, or leave it out for all. With no names saved it asks the gateway which valves are paired, so it shows every valve — name them with update_setting `thermostats` ("Living room=200, Bedroom=201") once the user says which is which. Report only the numbers '
            . 'returned. current_c is measured at the valve, which sits on the radiator, so it reads warmer than the room.';
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
        $named = trim((string) $this->settings->get($userId, 'thermostats'));
        $all   = Thermostats::parse($named);
        if ($named === '') {
            // Nothing named yet: ask the gateway which valves are paired (ids 200–204) rather than assume one.
            $d = Thermostats::discover(fn (int $id): array => $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $id], $userId));
            if ($d['error'] !== null) {
                return ['error' => $d['error']];
            }
            $all = $d['found'] === [] ? $all : array_map(static fn (int $id): array => ['name' => count($d['found']) === 1 ? 'Thermostat' : 'Thermostat ' . $id, 'id' => $id], $d['found']);
        }
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
            $remote = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetRemoteStatus?id=' . $t['id']], $userId);
            if (isset($remote['error'])) {
                return ['error' => $remote['error']];
            }
            $r   = is_array($remote['response'] ?? null) ? $remote['response'] : [];
            $tr  = is_array($r['status'] ?? null) ? $r['status'] : $r;    // answers come either wrapped in "status" or bare
            $st  = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $t['id']], $userId);
            $s   = is_array($st['response'] ?? null) ? $st['response'] : [];
            $out[] = array_filter([
                'name'      => $t['name'],
                'id'        => $t['id'],
                'current_c' => $tr['current_C'] ?? ($tr['tmp']['value'] ?? null),
                'target_c'  => $tr['target_C'] ?? ($tr['trv']['target_C'] ?? null),
                'valve_open_pct' => $tr['pos'] ?? null,
                'battery_pct'    => $s['battery'] ?? null,
                'paired'    => $s['paired'] ?? null,
                'errors'    => !empty($tr['errors']) ? $tr['errors'] : null,
            ], static fn ($v): bool => $v !== null);
        }

        return ['thermostats' => $out];
    }
}
