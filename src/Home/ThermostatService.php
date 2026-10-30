<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\HomeState;
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
    public function __construct(private Tool $home, private UserSettings $settings, private ?HomeState $state = null)
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
        // The valves almost never change: what is known (named in the setting, or discovered before) is used as it is, with no call to the home agent.
        $known = KnownValves::get($this->settings, $this->state, $userId);
        if ($known !== []) {
            return ['list' => $known, 'error' => null];
        }

        return $this->discoverAndKeep($userId);
    }

    /**
     * Asks the gateway which valves are paired and keeps the answer — what the nightly home sync and "refresh my home devices" do, so a valve that was
     * added or renamed shows up. With valves named in the setting nothing is discovered (the user's own list wins). Drops the cached readings when the list changed.
     *
     * @return array{list: list<array{name:string, id:int}>, error: ?string, changed: bool}
     */
    public function rediscover(int $userId): array
    {
        $before = KnownValves::get($this->settings, $this->state, $userId);
        if (trim((string) $this->settings->get($userId, 'thermostats')) !== '') {
            return ['list' => $before, 'error' => null, 'changed' => false];
        }
        $r = $this->discoverAndKeep($userId);
        $changed = $r['error'] === null && json_encode($r['list']) !== json_encode($before);
        if ($changed) {
            $this->state?->forget($userId, 'thermostats');   // the cached readings do not cover the new list
        }

        return $r + ['changed' => $changed];
    }

    /** @return array{list: list<array{name:string, id:int}>, error: ?string} */
    private function discoverAndKeep(int $userId): array
    {
        $d = Thermostats::discover(fn (int $id): array => $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetStatus?id=' . $id], $userId));
        if ($d['error'] !== null) {
            return ['list' => [], 'error' => $d['error']];
        }
        if ($d['found'] === []) {
            return ['list' => Thermostats::parse(''), 'error' => null];
        }
        $list = array_map(function (int $id) use ($d, $userId): array {
            $cfg  = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetConfig?id=' . $id], $userId);
            $c    = is_array($cfg['response'] ?? null) ? $cfg['response'] : [];
            $name = trim((string) ($c['name'] ?? ''));

            return ['name' => $name !== '' ? $name : (count($d['found']) === 1 ? 'Thermostat' : 'Thermostat ' . $id), 'id' => $id];
        }, $d['found']);
        $this->state?->put($userId, 'valves', ['list' => $list]);

        return ['list' => $list, 'error' => null];
    }

    /**
     * Every thermostat's numbers, from the cache when it is fresh (no Pico call at all), else read live one valve after another and cached.
     * `fresh` forces the live read. Rows from the cache get their "heard N minutes ago" recomputed and carry `cached_minutes_ago`.
     *
     * @return array{rows: list<array<string, mixed>>, error: ?string, at: ?int, cached: bool}
     */
    public function rows(int $userId, bool $fresh = false): array
    {
        if (!$fresh) {
            $hit = $this->state?->fresh($userId, 'thermostats');
            if ($hit !== null && is_array($hit['data']['rows'] ?? null)) {
                $now = time();

                return ['rows' => array_map(static function (array $r) use ($hit, $now): array {
                    if (isset($r['updated_ts'])) {
                        $r['last_heard_minutes_ago'] = max(0, (int) round(($now - (int) $r['updated_ts']) / 60));
                    }
                    $r['cached_minutes_ago'] = max(0, (int) round(($now - $hit['at']) / 60));

                    return $r;
                }, $hit['data']['rows']), 'error' => null, 'at' => $hit['at'], 'cached' => true];
            }
        }
        $l = $this->list($userId);
        if ($l['error'] !== null) {
            return ['rows' => [], 'error' => $l['error'], 'at' => null, 'cached' => false];
        }
        $rows = [];
        foreach ($l['list'] as $t) {
            $r      = $this->read($userId, $t);
            $rows[] = isset($r['error']) ? ['name' => $t['name'], 'id' => $t['id'], 'error' => $r['error']] : $r['row'];
        }
        $at = time();
        // A read that failed for EVERY valve (the gateway is down) is not state worth keeping.
        if ($rows !== [] && count(array_filter($rows, static fn (array $r): bool => !isset($r['error']))) > 0) {
            $prev = $this->state?->get($userId, 'thermostats');
            $this->state?->put($userId, 'thermostats', ['rows' => $rows] + (isset($prev['data']['hook_at']) ? ['hook_at' => $prev['data']['hook_at']] : []), $at);
        }

        return ['rows' => $rows, 'error' => null, 'at' => $at, 'cached' => false];
    }

    /** After a successful (or sent) set: the cached target of that valve now says what was asked, so the next read does not show the old one. */
    public function noteTarget(int $userId, int $id, float $targetC): void
    {
        $e = $this->state?->get($userId, 'thermostats');
        if ($e === null || !is_array($e['data']['rows'] ?? null)) {
            return;
        }
        $rows = array_map(static function (array $r) use ($id, $targetC): array {
            if ((int) ($r['id'] ?? -1) === $id) {
                $r['target_c'] = $targetC;
            }

            return $r;
        }, $e['data']['rows']);
        $this->state?->put($userId, 'thermostats', ['rows' => $rows] + array_diff_key($e['data'], ['rows' => 1]), $e['at']);   // keeps its age and its other keys (hook_at): only the target was patched
    }

    /** How long after the gateway's last call we still count it as "installed and working" (the valves report every 5 minutes). */
    private const HOOK_ALIVE_S = 900;

    /**
     * A valve reported through the gateway's script (api/shelly-hook.php): its row in the cached readings is replaced by what it said, and the cache is
     * marked as fed by the gateway. A valve not in the cache yet is added. Keeps the other valves' rows untouched.
     *
     * @param array{id:int, target_c:?float, current_c:?float, valve_open_pct:?int, battery_pct:?int, signal_dbm:?int, updated_ts:?int} $r
     */
    public function acceptReport(int $userId, array $r): void
    {
        if ($this->state === null) {
            return;
        }
        $e    = $this->state->get($userId, 'thermostats');
        $rows = is_array($e['data']['rows'] ?? null) ? $e['data']['rows'] : [];
        $name = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? -1) === $r['id']) {
                $name = $row['name'] ?? null;
            }
        }
        foreach (KnownValves::get($this->settings, $this->state, $userId) as $v) {
            if ($name === null && $v['id'] === $r['id']) {
                $name = $v['name'];
            }
        }
        $new = array_filter([
            'name'           => $name ?? 'Thermostat ' . $r['id'],
            'id'             => $r['id'],
            'current_c'      => $r['current_c'],
            'target_c'       => $r['target_c'],
            'valve_open_pct' => $r['valve_open_pct'],
            'battery_pct'    => $r['battery_pct'],
            'signal_dbm'     => $r['signal_dbm'],
            'updated_ts'     => $r['updated_ts'],
            'paired'         => true,
        ], static fn ($v): bool => $v !== null);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['id'] ?? -1) !== $r['id']));
        $rows[] = $new;
        usort($rows, static fn (array $a, array $b): int => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $this->state->put($userId, 'thermostats', ['rows' => $rows, 'hook_at' => time()]);
    }

    /** Whether the gateway's script has called in recently — then the valves' confirmations arrive by themselves, with no polling. */
    public function hookActive(int $userId): bool
    {
        $e = $this->state?->get($userId, 'thermostats');

        return $e !== null && time() - (int) ($e['data']['hook_at'] ?? 0) <= self::HOOK_ALIVE_S;
    }

    /**
     * Just the target a valve reports (one call to the home agent instead of the two a full read makes) — what a confirmation watch polls. Null when it cannot be read.
     */
    public function targetOf(int $userId, int $id): ?float
    {
        $remote = $this->home->execute(['device' => 'shelly', 'method' => 'GET', 'path' => '/rpc/BluTrv.GetRemoteStatus?id=' . $id], $userId);
        if (isset($remote['error']) || !is_array($remote['response'] ?? null)) {
            return null;
        }
        $t = Thermostats::find($remote['response'], 'target_C');

        return is_numeric($t) ? (float) $t : null;
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
            'updated_ts'     => isset($s['last_updated_ts']) && is_numeric($s['last_updated_ts']) ? (int) $s['last_updated_ts'] : null,   // the gateway's own "heard at" (the probe logs it)
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
