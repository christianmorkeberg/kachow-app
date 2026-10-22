<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\LocationPoints;
use App\Data\Places;

/**
 * Where the user is RIGHT NOW against their saved places and regions, from their latest location point — for routine conditions ("only
 * when I'm east of Roskilde") and for the where_am_i tool. Only a fresh, accurate point counts; without one every answer is "unknown"
 * (null), which never blocks a routine.
 */
final class PlaceNow
{
    public const MAX_AGE_SECONDS = 20 * 60;
    public const MAX_ACC_M       = 100;

    /** The latest point if it is fresh and accurate enough to trust, else null. @param array{recorded_at:string, lat:float, lon:float, acc:?int}|null $point */
    public static function usable(?array $point, int $nowTs): ?array
    {
        if ($point === null) {
            return null;
        }
        $ts = strtotime($point['recorded_at'] . ' UTC');
        if ($ts === false || $nowTs - $ts > self::MAX_AGE_SECONDS || $nowTs < $ts - 300) {
            return null;
        }
        if ($point['acc'] !== null && $point['acc'] > self::MAX_ACC_M) {
            return null;
        }

        return $point;
    }

    /**
     * Place id → is the user in it (null = cannot tell).
     *
     * @param array{recorded_at:string, lat:float, lon:float, acc:?int}|null $point
     * @param list<array<string, mixed>> $places
     * @param list<int> $ids
     * @return array<int, ?bool>
     */
    public static function inside(?array $point, array $places, array $ids, int $nowTs): array
    {
        $p   = self::usable($point, $nowTs);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = null;
        }
        if ($p === null) {
            return $out;
        }
        foreach ($places as $pl) {
            if (array_key_exists((int) $pl['id'], $out)) {
                $out[(int) $pl['id']] = Places::contains($pl, $p['lat'], $p['lon']);
            }
        }

        return $out;
    }

    /** Convenience for the cron: ids → inside, reading the latest point and the places itself. @param list<int> $ids @return array<int, ?bool> */
    public static function check(LocationPoints $points, Places $places, int $userId, array $ids, ?int $nowTs = null): array
    {
        try {
            return self::inside($points->latest($userId), $places->list($userId), $ids, $nowTs ?? time());
        } catch (\Throwable) {
            return array_fill_keys($ids, null);
        }
    }
}
