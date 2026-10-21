<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\UserSettings;
use App\Support\RoutineSteps;
use App\Support\Thermostats;
use App\Tools\Tool;

/**
 * Reads the user's radiator thermostats (Shelly BLU TRV valves behind the home agent's "shelly" device): which exist, and what each
 * says. Shared by the get_thermostats tool and the Home card. $home is the home_device_call tool.
 */
final class ThermostatService
{
    public function __construct(private Tool $home, private UserSettings $settings)
    {
    }

    /**
     * The thermostats to read: the named ones from the setting, or — with none named — whichever valves the gateway has paired,
     * named as the Shelly app names them when the gateway knows.
     *
     * @return array{list: list<array{name:string, id:int}>, error: ?string}
     */
    public function list(int $userId): array
    {
        $named = trim((string) $this->settings->get($userId, 'thermostats'));
        $all   = Thermostats::parse($named);
        if ($named !== '') {
            return ['list' => $all, 'error' => null];
        }
        $d = Thermostats::discover(fn (int $id): array => $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $id], $userId));
        if ($d['error'] !== null) {
            return ['list' => [], 'error' => $d['error']];
        }
        if ($d['found'] === []) {
            return ['list' => $all, 'error' => null];
        }

        return ['list' => array_map(function (int $id) use ($d, $userId): array {
            $cfg  = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetConfig?id=' . $id], $userId);
            $c    = is_array($cfg['response'] ?? null) ? $cfg['response'] : [];
            $name = trim((string) ($c['name'] ?? ''));

            return ['name' => $name !== '' ? $name : (count($d['found']) === 1 ? 'Thermostat' : 'Thermostat ' . $id), 'id' => $id];
        }, $d['found']), 'error' => null];
    }

    /**
     * One thermostat's numbers: current / target temperature, valve opening, battery, signal, how long ago it was heard.
     *
     * @param array{name:string, id:int} $t
     * @return array{row?: array<string, mixed>, error?: string}
     */
    public function read(int $userId, array $t): array
    {
        $remote = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetRemoteStatus?id=' . $t['id']], $userId);
        if (isset($remote['error'])) {
            return ['error' => (string) $remote['error']];
        }
        $r  = is_array($remote['response'] ?? null) ? $remote['response'] : [];
        $st = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $t['id']], $userId);
        $s  = is_array($st['response'] ?? null) ? $st['response'] : [];
        $row = array_filter([
            'name'      => $t['name'],
            'id'        => $t['id'],
            'current_c' => Thermostats::find($r, 'current_C') ?? Thermostats::find($r, 'tmp'),
            'target_c'  => Thermostats::find($r, 'target_C'),
            'valve_open_pct' => Thermostats::find($r, 'pos'),
            'battery_pct'    => $s['battery'] ?? Thermostats::find($r, 'battery'),
            'signal_dbm'     => $s['rssi'] ?? null,
            'last_heard_minutes_ago' => isset($s['last_updated_ts']) && is_numeric($s['last_updated_ts']) ? max(0, (int) round((time() - (int) $s['last_updated_ts']) / 60)) : null,
            'paired'    => $s['paired'] ?? null,
            'errors'    => !empty(Thermostats::find($r, 'errors')) ? Thermostats::find($r, 'errors') : null,
        ], static fn ($v): bool => $v !== null);
        if (!isset($row['current_c']) && !isset($row['target_c'])) {
            $row['note'] = 'No temperature in the valve\'s answer — it may not have reported yet (it reports every few minutes). Raw answer: '
                . mb_substr((string) json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 600);
        }

        return ['row' => $row];
    }

    /** The Hue room a thermostat sits in, from the `thermostat_rooms` setting ("Stue=Living room, …"); with none set, a room of the same name. */
    public function roomFor(int $userId, string $thermostatName): string
    {
        foreach (preg_split('/[;,\n]+/', (string) $this->settings->get($userId, 'thermostat_rooms')) ?: [] as $pair) {
            if (preg_match('/^\s*(.+?)\s*=\s*(.+?)\s*$/u', $pair, $m) === 1 && mb_strtolower($m[1]) === mb_strtolower($thermostatName)) {
                return $m[2];
            }
        }

        return $thermostatName;
    }
}
