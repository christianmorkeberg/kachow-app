<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Places;

/**
 * Telling "arrived" / "left" from a stream of positions, for place routines — using the user's own saved places, so
 * whatever shape they drew (a circle, a polygon around a campus) is what counts as inside. Pure: previous state + one
 * new position in, new state + at most one event out.
 *
 *  - Inaccurate points (> MAX_ACC_M) say nothing: neither inside nor outside.
 *  - The first accurate point only sets the baseline: no event.
 *  - LEFT: the user was inside and then LEAVE_POINTS accurate points in a row are outside (one stray jump is not leaving).
 *  - ARRIVED: the user was outside and an accurate point is inside and slow (≤ MAX_KMH — driving past is not arriving).
 */
final class PlaceTransitions
{
    public const MAX_ACC_M     = 100;
    public const MAX_KMH       = 10;
    public const LEAVE_POINTS  = 2;

    /**
     * @param array{inside:bool, streak:int}|null $state null = never seen
     * @param list<array<string, mixed>> $targets the watched places (Places::list rows)
     * @param array{lat:float, lon:float, acc:?int, vel:?int} $point
     * @return array{state:array{inside:bool, streak:int}|null, event:?string} event is 'enter' | 'leave' | null; state null = unchanged
     */
    public static function step(?array $state, array $targets, array $point): array
    {
        if ($targets === [] || ($point['acc'] !== null && $point['acc'] > self::MAX_ACC_M)) {
            return ['state' => null, 'event' => null];
        }
        $inside = false;
        foreach ($targets as $p) {
            if (Places::matchesStay($p, $point['lat'], $point['lon'])) {
                $inside = true;
                break;
            }
        }
        if ($state === null) {
            return ['state' => ['inside' => $inside, 'streak' => 0], 'event' => null];
        }
        if ($inside === $state['inside']) {
            return ['state' => ['inside' => $state['inside'], 'streak' => 0], 'event' => null];
        }
        // the point disagrees with what we believed
        if ($state['inside']) {                                   // believed inside, now outside
            $streak = $state['streak'] + 1;
            if ($streak >= self::LEAVE_POINTS) {
                return ['state' => ['inside' => false, 'streak' => 0], 'event' => 'leave'];
            }

            return ['state' => ['inside' => true, 'streak' => $streak], 'event' => null];
        }
        // believed outside, now inside
        if ($point['vel'] !== null && $point['vel'] > self::MAX_KMH) {
            return ['state' => null, 'event' => null];            // passing through
        }

        return ['state' => ['inside' => true, 'streak' => 0], 'event' => 'enter'];
    }
}
