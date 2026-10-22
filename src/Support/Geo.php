<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\LocationPoints;

/**
 * Small geography for the assistant: which way is one point from another, and a "region" as a polygon — everything east / west / north /
 * south of a reference point, out to some distance. A region is an ordinary polygon place, so it works with every place trigger and
 * condition. Pure.
 */
final class Geo
{
    public const SIDES = ['east_of', 'west_of', 'north_of', 'south_of'];
    public const MIN_EXTENT_KM = 10;
    public const MAX_EXTENT_KM = 500;
    public const DEFAULT_EXTENT_KM = 150;

    /** Initial bearing in degrees (0 = north, 90 = east) from the first point to the second. */
    public static function bearing(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2); $dl = deg2rad($lon2 - $lon1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);

        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** "NE", "SW" … for a bearing. */
    public static function compass(float $bearing): string
    {
        return ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][(int) floor(fmod($bearing + 22.5, 360.0) / 45.0)];
    }

    /**
     * Where a point lies relative to a reference: distance, compass direction, and the four plain yes / no answers (longitude / latitude
     * compared — "east of Roskilde" means a larger longitude).
     *
     * @return array{distance_km: float, compass: string, east_of: bool, west_of: bool, north_of: bool, south_of: bool}
     */
    public static function relative(float $lat, float $lon, float $refLat, float $refLon): array
    {
        return [
            'distance_km' => round(LocationPoints::haversineKm($refLat, $refLon, $lat, $lon), 1),
            'compass'     => self::compass(self::bearing($refLat, $refLon, $lat, $lon)),
            'east_of'     => $lon > $refLon, 'west_of' => $lon < $refLon, 'north_of' => $lat > $refLat, 'south_of' => $lat < $refLat,
        ];
    }

    /**
     * The corners [lat, lon] of the area east / west / north / south of a reference point, reaching $extentKm out and $extentKm to each side.
     *
     * @return list<array{0: float, 1: float}>|null null for an unknown side or a reference too near a pole
     */
    public static function region(string $side, float $refLat, float $refLon, float $extentKm = self::DEFAULT_EXTENT_KM): ?array
    {
        if (!in_array($side, self::SIDES, true) || abs($refLat) > 80.0) {
            return null;
        }
        $extentKm = max(self::MIN_EXTENT_KM, min(self::MAX_EXTENT_KM, $extentKm));
        $dLat = $extentKm / 111.32;
        $dLon = $extentKm / (111.32 * cos(deg2rad($refLat)));
        $c = static fn (float $lat, float $lon): array => [round(max(-89.9, min(89.9, $lat)), 6), round(max(-179.9, min(179.9, $lon)), 6)];

        return match ($side) {
            'east_of'  => [$c($refLat - $dLat, $refLon), $c($refLat + $dLat, $refLon), $c($refLat + $dLat, $refLon + $dLon), $c($refLat - $dLat, $refLon + $dLon)],
            'west_of'  => [$c($refLat - $dLat, $refLon - $dLon), $c($refLat + $dLat, $refLon - $dLon), $c($refLat + $dLat, $refLon), $c($refLat - $dLat, $refLon)],
            'north_of' => [$c($refLat, $refLon - $dLon), $c($refLat, $refLon + $dLon), $c($refLat + $dLat, $refLon + $dLon), $c($refLat + $dLat, $refLon - $dLon)],
            'south_of' => [$c($refLat - $dLat, $refLon - $dLon), $c($refLat - $dLat, $refLon + $dLon), $c($refLat, $refLon + $dLon), $c($refLat, $refLon - $dLon)],
        };
    }
}
