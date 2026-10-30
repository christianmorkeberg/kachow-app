<?php

declare(strict_types=1);

namespace App\Home;

use App\Data\HomeState;
use App\Data\UserSettings;
use App\Support\Thermostats;

/**
 * The radiator valves we ALREADY know — names and ids on the gateway — without asking the home agent anything: the ones named in the `thermostats`
 * setting, else the list discovered earlier and kept in the home-state cache. The valves almost never change, so this list is not aged out: it is
 * refreshed by the nightly home sync and by "refresh my home devices" (ThermostatService::rediscover). Empty when nothing is known yet.
 */
final class KnownValves
{
    /** @return list<array{name:string, id:int}> */
    public static function get(UserSettings $settings, ?HomeState $state, int $userId): array
    {
        $named = trim((string) $settings->get($userId, 'thermostats'));
        if ($named !== '') {
            return Thermostats::parse($named);
        }
        $known = $state?->get($userId, 'valves');
        $list  = is_array($known['data']['list'] ?? null) ? $known['data']['list'] : [];

        return array_values(array_filter($list, static fn ($t): bool => is_array($t) && isset($t['name'], $t['id'])));
    }
}
