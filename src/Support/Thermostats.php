<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The user's radiator thermostats (Shelly BLU TRV valves behind a BLU gateway), as the `thermostats` setting writes them:
 * "Living room=200, Bedroom=201" — a name for each valve's id on the gateway. With nothing set, the first valve (id 200) is
 * "Thermostat". Pure.
 */
final class Thermostats
{
    public const DEFAULT_ID = 200;
    /** A gateway holds up to 5 valves, ids 200–204 (the first one paired is 200). */
    public const PROBE_IDS = [200, 201, 202, 203, 204];
    public const MIN_C = 4.0;
    public const MAX_C = 30.0;

    /** @return list<array{name:string, id:int}> */
    public static function parse(?string $setting): array
    {
        $out = [];
        foreach (preg_split('/[;,\n]+/', (string) $setting) ?: [] as $part) {
            if (preg_match('/^\s*(.+?)\s*[=:]\s*(\d{1,6})\s*$/u', $part, $m) === 1) {
                $out[] = ['name' => trim($m[1]), 'id' => (int) $m[2]];
            }
        }

        return $out === [] ? [['name' => 'Thermostat', 'id' => self::DEFAULT_ID]] : $out;
    }

    /**
     * The valves a gateway really has paired: ids 200–204 asked through $status(id) → BluTrv.GetStatus answer. A valve counts when its
     * answer says paired; a gateway that cannot be reached at all is an error, not "no valves".
     *
     * @param callable(int): array<string, mixed> $status
     * @return array{found:list<int>, error:?string}
     */
    public static function discover(callable $status): array
    {
        $found = [];
        foreach (self::PROBE_IDS as $id) {
            $res = $status($id);
            if (isset($res['error'])) {
                if (RoutineSteps::unreachable((string) $res['error']) || $id === self::DEFAULT_ID && str_contains((string) $res['error'], 'not allowed')) {
                    return ['found' => [], 'error' => (string) $res['error']];
                }
                continue;                      // an id with no valve answers with an error: simply not there
            }
            $r = is_array($res['response'] ?? null) ? $res['response'] : [];
            if (!empty($r['paired'])) {
                $found[] = $id;
            }
        }

        return ['found' => $found, 'error' => null];
    }

    /**
     * One thermostat by name (case-insensitive, also a part of the name) or id; with nothing asked and only one thermostat, that one.
     *
     * @param list<array{name:string, id:int}> $all
     * @return array{name:string, id:int}|null
     */
    public static function resolve(array $all, string $query): ?array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') {
            return count($all) === 1 ? $all[0] : null;
        }
        if (preg_match('/^\d{3}$/', $q) === 1 && in_array((int) $q, self::PROBE_IDS, true)) {
            foreach ($all as $t) {
                if ($t['id'] === (int) $q) {
                    return $t;
                }
            }

            return ['name' => 'Thermostat ' . $q, 'id' => (int) $q];   // a valve the user has not named: its id is enough
        }
        foreach ($all as $t) {
            if ((string) $t['id'] === $q || mb_strtolower($t['name']) === $q) {
                return $t;
            }
        }
        $hits = array_values(array_filter($all, static fn (array $t): bool => str_contains(mb_strtolower($t['name']), $q) || str_contains($q, mb_strtolower($t['name']))));

        return count($hits) === 1 ? $hits[0] : null;
    }

    /** First value for $key anywhere in a nested answer (the gateway wraps a valve's numbers differently from firmware to firmware), or null. */
    public static function find(mixed $data, string $key): mixed
    {
        if (!is_array($data)) {
            return null;
        }
        if (array_key_exists($key, $data) && !is_array($data[$key]) && $data[$key] !== null) {
            return $data[$key];
        }
        foreach ($data as $v) {
            $hit = self::find($v, $key);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /** A target in °C, rounded to the valve's half degrees, or null if it is outside what the valve accepts. */
    public static function target(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $t = round(((float) $value) * 2) / 2;

        return $t >= self::MIN_C && $t <= self::MAX_C ? $t : null;
    }
}
