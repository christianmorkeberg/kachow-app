<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Maps\MapDistance;
use App\Support\DeviceFix;
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

    /** A geocoded address further than this from the user's latest position is almost surely a mismatch. */
    public const MAX_ADDRESS_KM = 300;

    /** "Addresses" that really mean the current position ("I'm at home right now" → address "home"). */
    private const HERE_WORDS = '/^(here|home|at home|my home|my place|where i am|my (current )?(location|position)|'
        . 'current (location|position)|her|hjem|hjemme|derhjemme|mit hjem|hvor jeg er|'
        . 'min (nuværende )?(placering|position))$/iu';

    /** @return array{lat:float, lon:float, from:string}|array{error:string} */
    public static function resolve(LocationPoints $points, MapDistance $maps, int $userId, ?string $address): array
    {
        $address = trim((string) $address);
        if (preg_match(self::HERE_WORDS, trim($address, " \t.!")) === 1) {
            $address = '';
        }
        $last = $points->latest($userId);
        $near = $last ?? DeviceFix::get();
        if ($address !== '') {
            try {
                $g = $maps->geocodeAddress($address, $near['lat'] ?? null, $near['lon'] ?? null);
            } catch (Throwable $e) {
                return ['error' => 'Could not find that address (' . $e->getMessage() . '). Try a fuller address, or '
                    . 'save the place from the map / while standing there.'];
            }
            if ($near !== null) {
                $km = LocationPoints::haversineKm($near['lat'], $near['lon'], $g['lat'], $g['lon']);
                if ($km > self::MAX_ADDRESS_KM) {
                    return ['error' => '"' . $address . '" matched "' . $g['label'] . '", ' . (int) round($km)
                        . ' km from the user\'s latest position — almost certainly the wrong place, so nothing was '
                        . 'saved. Ask for a full street address with town, or save it while standing there '
                        . '(leave address out).'];
                }
            }

            return ['lat' => $g['lat'], 'lon' => $g['lon'], 'from' => 'address: ' . $g['label']];
        }

        // The phone's own fix sent with this message (the browser asks for it on "I'm at…" /
        // "jeg er hjemme") stands in when OwnTracks has nothing fresh and accurate.
        $fix       = DeviceFix::get();
        $fixUsable = $fix !== null && $fix['acc'] !== null && $fix['acc'] <= self::MAX_ACC_M;
        $ageMin    = $last !== null ? (int) round((time() - strtotime($last['recorded_at'] . ' UTC')) / 60) : null;
        $lastOk    = $last !== null && $ageMin <= self::MAX_AGE_MIN && ($last['acc'] === null || $last['acc'] <= self::MAX_ACC_M);
        if (!$lastOk && $fixUsable) {
            return ['lat' => $fix['lat'], 'lon' => $fix['lon'], 'from' => 'current position (phone, ±' . $fix['acc'] . ' m)'];
        }

        if ($last === null) {
            return ['error' => 'No tracked position yet — set up location tracking first (get_location_tracking_setup), '
                . 'or give an address.'];
        }
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
