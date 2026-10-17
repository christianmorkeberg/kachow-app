<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Places;

/**
 * When a location-triggered reminder ("next time I'm at <place>") should fire. Pure: given the
 * user's pending place reminders, their places and one new position, says which reminders fire
 * and which become armed.
 *
 *  - A reminder targets one place (place_id) or every place of a type (place_type, e.g. work).
 *  - It fires on the first position INSIDE a target place that is accurate (≤ MAX_ACC_M), slow
 *    (≤ MAX_KMH, so driving past is not "being there"), and newer than the reminder itself
 *    (OwnTracks re-sends queued old fixes).
 *  - A reminder set while already there starts unarmed: a position OUTSIDE every target place
 *    arms it, so "next time" means the next visit.
 */
final class PlaceArrival
{
    public const MAX_ACC_M = 100;
    public const MAX_KMH   = 10;

    /**
     * @param list<array{id:int, place_id:?int, place_type:?string, armed:bool, created_ts:int}> $reminders
     * @param list<array<string, mixed>> $places  Places::list() rows
     * @param array{ts:int, lat:float, lon:float, acc:?int, vel:?int} $point
     * @return array{fire: list<int>, arm: list<int>}
     */
    public static function check(array $reminders, array $places, array $point): array
    {
        $fire = $arm = [];
        if ($point['acc'] !== null && $point['acc'] > self::MAX_ACC_M) {
            return ['fire' => [], 'arm' => []];   // too vague to say inside or outside
        }
        foreach ($reminders as $r) {
            if ($point['ts'] < $r['created_ts']) {
                continue;
            }
            $targets = array_filter($places, static fn (array $p): bool => $r['place_id'] !== null
                ? $p['id'] === $r['place_id']
                : $r['place_type'] !== null && $p['type'] === $r['place_type']);
            if ($targets === []) {
                continue;   // the place was deleted (or no place of that type yet): nothing to arrive at
            }
            $inside = false;
            foreach ($targets as $p) {
                if (Places::matchesStay($p, $point['lat'], $point['lon'])) {
                    $inside = true;
                    break;
                }
            }
            if (!$r['armed']) {
                if (!$inside) {
                    $arm[] = $r['id'];
                }
                continue;
            }
            if ($inside && ($point['vel'] === null || $point['vel'] <= self::MAX_KMH)) {
                $fire[] = $r['id'];
            }
        }

        return ['fire' => $fire, 'arm' => $arm];
    }

    /**
     * Whether a position is inside any of the given places (for "you're there right now" at
     * creation time). Same accuracy rule as check().
     *
     * @param list<array<string, mixed>> $places
     */
    public static function insideAny(array $places, float $lat, float $lon, ?int $acc): bool
    {
        if ($acc !== null && $acc > self::MAX_ACC_M) {
            return false;
        }
        foreach ($places as $p) {
            if (Places::matchesStay($p, $lat, $lon)) {
                return true;
            }
        }

        return false;
    }
}
