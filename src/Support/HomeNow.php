<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\LocationPoints;
use App\Data\Places;

/**
 * Is the user at a HOME-type place right now, going by their latest location point? Used to tag a clock-in made from chat as a
 * work-from-home one, so "how did this punch get here?" has an answer (report #49). Only a fresh, accurate point counts.
 */
final class HomeNow
{
    public const MAX_AGE_SECONDS = 20 * 60;
    public const MAX_ACC_M       = 100;

    public static function check(LocationPoints $points, Places $places, int $userId, ?int $nowTs = null): bool
    {
        try {
            return self::atHome($points->latest($userId), $places->list($userId), $nowTs ?? time());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{recorded_at:string, lat:float, lon:float, acc:?int}|null $point the latest point
     * @param list<array<string, mixed>> $places
     */
    public static function atHome(?array $point, array $places, int $nowTs): bool
    {
        if ($point === null) {
            return false;
        }
        $ts = strtotime($point['recorded_at'] . ' UTC');
        if ($ts === false || $nowTs - $ts > self::MAX_AGE_SECONDS || $nowTs < $ts - 300) {
            return false;
        }
        if ($point['acc'] !== null && $point['acc'] > self::MAX_ACC_M) {
            return false;
        }
        foreach ($places as $p) {
            if (($p['type'] ?? '') === 'home' && Places::contains($p, $point['lat'], $point['lon'])) {
                return true;
            }
        }

        return false;
    }
}
