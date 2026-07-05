<?php

declare(strict_types=1);

namespace App\Tools;

use App\Weather\Dmi;

/**
 * Tool: current weather from DMI (Danish Meteorological Institute) observations,
 * for the nearest Synop station to a lat/lon.
 *
 * The model supplies latitude/longitude — either from the user's device location
 * (injected into the system prompt) or from a place the user names. This gives
 * observed conditions right now, not a forecast.
 */
final class GetCurrentWeather implements Tool
{
    private const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    public function __construct(private Dmi $dmi)
    {
    }

    public function name(): string
    {
        return 'get_current_weather';
    }

    public function description(): string
    {
        return 'Gets the current (observed) weather in Denmark from DMI: temperature, wind, '
            . 'humidity, rain in the last hour, and pressure, from the nearest weather station. '
            . 'Provide latitude and longitude — use the user\'s device location if it is given to '
            . 'you, otherwise the coordinates of the place they mention. This is live observations, '
            . 'not a forecast, and covers Denmark only.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'latitude'  => ['type' => 'number', 'description' => 'Latitude (WGS84), e.g. 55.68 for Copenhagen.'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude (WGS84), e.g. 12.57 for Copenhagen.'],
                'place'     => ['type' => 'string', 'description' => 'Optional label for the location, e.g. "Aarhus".'],
            ],
            'required' => ['latitude', 'longitude'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!isset($arguments['latitude'], $arguments['longitude'])
            || !is_numeric($arguments['latitude']) || !is_numeric($arguments['longitude'])) {
            return ['error' => 'I need a latitude and longitude (in Denmark) to check the weather.'];
        }
        $lat = (float) $arguments['latitude'];
        $lon = (float) $arguments['longitude'];

        $stations = $this->dmi->nearbyStations($lat, $lon);
        if ($stations === []) {
            return ['error' => 'No Danish weather station found near there. DMI covers Denmark only.'];
        }

        // Try nearest-first until a station actually has recent readings.
        $station = null;
        $obs     = [];
        foreach ($stations as $candidate) {
            $obs = $this->dmi->latestObservations($candidate['id']);
            if ($obs !== []) {
                $station = $candidate;
                break;
            }
        }
        if ($station === null) {
            return ['error' => 'No recent readings from any weather station near there right now.'];
        }

        $val = static fn (string $p) => isset($obs[$p]) ? $obs[$p]['value'] : null;

        $result = [
            'place'             => (string) ($arguments['place'] ?? '') ?: $station['name'],
            'station'           => $station['name'],
            'distance_km'       => $station['distance_km'],
            'observed'          => $obs['temp_dry']['observed'] ?? reset($obs)['observed'] ?? null,
            'temperature_c'     => $val('temp_dry'),
            'temp_max_1h_c'     => $val('temp_max_past1h'),
            'temp_min_1h_c'     => $val('temp_min_past1h'),
            'precip_past1h_mm'  => $val('precip_past1h'),
            'humidity_pct'      => $val('humidity'),
            'wind_ms'           => $val('wind_speed'),
            'wind_from'         => $val('wind_dir') !== null ? self::compass((float) $val('wind_dir')) : null,
            'pressure_hpa'      => $val('pressure_at_sea'),
        ];

        // Drop keys we couldn't measure so the model doesn't report nulls.
        return array_filter($result, static fn ($v): bool => $v !== null && $v !== '');
    }

    private static function compass(float $deg): string
    {
        $i = (int) round(($deg % 360) / 45) % 8;

        return self::COMPASS[$i];
    }
}
