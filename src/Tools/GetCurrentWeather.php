<?php

declare(strict_types=1);

namespace App\Tools;

use App\Weather\WeatherService;

/**
 * Tool: current weather for a lat/lon. Prefers DMI (Danish Meteorological
 * Institute) observations from the nearest station; falls back to Open-Meteo when
 * DMI is busy or the location is outside Denmark — so it works worldwide.
 *
 * The model supplies latitude/longitude — either from the user's device location
 * (injected into the system prompt) or from a place the user names. This gives
 * conditions right now, not a forecast.
 */
final class GetCurrentWeather implements Tool
{
    public function __construct(private WeatherService $weather)
    {
    }

    public function name(): string
    {
        return 'get_current_weather';
    }

    public function description(): string
    {
        return 'Gets the current weather: temperature, wind, humidity, recent rain, and pressure. '
            . 'Uses DMI weather-station observations in Denmark and automatically falls back to '
            . 'Open-Meteo elsewhere or when DMI is busy, so it works worldwide. Provide latitude and '
            . 'longitude — use the user\'s device location if it is given to you, otherwise the '
            . 'coordinates of the place they mention. This is current conditions, not a forecast.';
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

        $cur = $this->weather->current($lat, $lon);
        if ($cur === null) {
            return ['error' => 'I couldn\'t get the weather for that location right now. Please try again shortly.'];
        }

        $place = trim((string) ($arguments['place'] ?? ''));
        if ($place === '') {
            $place = ($cur['place'] ?? '') !== '' ? (string) $cur['place'] : 'your location';
        }

        // Drop keys we couldn't measure so the model doesn't report nulls.
        $result = array_filter([
            'place'            => $place,
            'source'           => $cur['source'] ?? null,
            'station'          => $cur['station'] ?? null,
            'distance_km'      => $cur['distance_km'] ?? null,
            'observed'         => $cur['observed'] ?? null,
            'temperature_c'    => $cur['temp_c'] ?? null,
            'precip_past1h_mm' => $cur['precip_mm'] ?? null,
            'humidity_pct'     => $cur['humidity_pct'] ?? null,
            'wind_ms'          => $cur['wind_ms'] ?? null,
            'wind_from'        => $cur['wind_from'] ?? null,
            'pressure_hpa'     => $cur['pressure_hpa'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== '');

        // Interactive weather card for the chat (the model gets the numbers too, but
        // should summarise rather than recite them — the card shows the detail).
        $result['_render'] = [
            'kind'    => 'weather',
            'title'   => $place,
            'current' => array_filter([
                'temp_c'       => $cur['temp_c'] ?? null,
                'precip_mm'    => $cur['precip_mm'] ?? null,
                'humidity_pct' => $cur['humidity_pct'] ?? null,
                'wind_ms'      => $cur['wind_ms'] ?? null,
                'wind_from'    => $cur['wind_from'] ?? null,
                'station'      => $cur['station'] ?? null,
                'observed'     => $cur['observed'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'days'    => [],
        ];

        return $result;
    }
}
