<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Maps\MapDistance;
use Throwable;

/**
 * Where to put a place the user names in chat: "here" (their latest tracked point, if it is
 * fresh and accurate enough) or an address (geocoded via OpenRouteService). Shared by
 * save_place and update_place. The model never supplies coordinates itself.
 */
final class PlacePosition
{
    /** "Here" must be at most this old — otherwise we'd save wherever they were earlier. */
    public const MAX_AGE_MIN = 30;

    /** A fix less accurate than this is too vague to anchor a place on. */
    private const MAX_ACC_M = 150;

    /** @return array{lat:float, lon:float, from:string}|array{error:string} */
    public static function resolve(LocationPoints $points, MapDistance $maps, int $userId, ?string $address): array
    {
        $address = trim((string) $address);
        if ($address !== '') {
            try {
                $g = $maps->geocodeAddress($address);
            } catch (Throwable $e) {
                return ['error' => 'Could not find that address (' . $e->getMessage() . '). Try a fuller address, or '
                    . 'save the place from the map / while standing there.'];
            }

            return ['lat' => $g['lat'], 'lon' => $g['lon'], 'from' => 'address: ' . $g['label']];
        }

        $last = $points->latest($userId);
        if ($last === null) {
            return ['error' => 'No tracked position yet — set up location tracking first (get_location_tracking_setup), '
                . 'or give an address.'];
        }
        $ageMin = (int) round((time() - strtotime($last['recorded_at'] . ' UTC')) / 60);
        if ($ageMin > self::MAX_AGE_MIN) {
            return ['error' => 'The latest tracked position is ' . $ageMin . ' min old, so "here" may be wrong. Ask the '
                . 'user to open OwnTracks (it then sends a fresh position) and try again, or give an address.'];
        }
        if ($last['acc'] !== null && $last['acc'] > self::MAX_ACC_M) {
            return ['error' => 'The latest position is only accurate to ±' . $last['acc'] . ' m — too vague for a place. '
                . 'Try again in a moment, or give an address.'];
        }

        return ['lat' => $last['lat'], 'lon' => $last['lon'], 'from' => 'current position (' . $ageMin . ' min ago'
            . ($last['acc'] !== null ? ', ±' . $last['acc'] . ' m' : '') . ')'];
    }

    /** The latest position as [lat, lon] for centring the map card, or null. */
    public static function here(LocationPoints $points, int $userId): ?array
    {
        $last = $points->latest($userId);

        return $last !== null ? [$last['lat'], $last['lon']] : null;
    }
}
