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
        foreach ($all as $t) {
            if ((string) $t['id'] === $q || mb_strtolower($t['name']) === $q) {
                return $t;
            }
        }
        $hits = array_values(array_filter($all, static fn (array $t): bool => str_contains(mb_strtolower($t['name']), $q) || str_contains($q, mb_strtolower($t['name']))));

        return count($hits) === 1 ? $hits[0] : null;
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
