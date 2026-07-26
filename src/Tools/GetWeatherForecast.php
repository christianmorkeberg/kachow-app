<?php

declare(strict_types=1);

namespace App\Tools;

use App\Weather\WeatherService;

/**
 * Tool: weather FORECAST for the coming hours/days. Prefers DMI's HARMONIE model,
 * falling back to Open-Meteo when DMI is busy or the location is outside its reach.
 * Complements get_current_weather (which is conditions right now).
 */
final class GetWeatherForecast implements Tool
{
    public function __construct(private WeatherService $weather)
    {
    }

    public function name(): string
    {
        return 'get_weather_forecast';
    }

    public function description(): string
    {
        return 'Gets the weather FORECAST for the next few days (temperature, rain, wind, cloud '
            . 'cover) — as a few upcoming hourly steps plus a per-day summary. Uses DMI and '
            . 'automatically falls back to Open-Meteo when DMI is busy or the location is elsewhere, '
            . 'so it works worldwide. Use for anything about the future: later today, tonight, '
            . 'tomorrow, the weekend. For conditions right now, use get_current_weather instead. '
            . 'Provide latitude and longitude — the user\'s device location if given, otherwise the '
            . 'place they mention. Times are local.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'latitude'  => ['type' => 'number', 'description' => 'Latitude (WGS84), e.g. 55.68.'],
                'longitude' => ['type' => 'number', 'description' => 'Longitude (WGS84), e.g. 12.57.'],
                'place'     => ['type' => 'string', 'description' => 'Optional label for the location, e.g. "Aarhus".'],
            ],
            'required' => ['latitude', 'longitude'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!isset($arguments['latitude'], $arguments['longitude'])
            || !is_numeric($arguments['latitude']) || !is_numeric($arguments['longitude'])) {
            return ['error' => 'I need a latitude and longitude to get a forecast.'];
        }

        $lat = (float) $arguments['latitude'];
        $lon = (float) $arguments['longitude'];

        $fc = $this->weather->forecast($lat, $lon);
        if ($fc === []) {
            return ['error' => 'No forecast is available for that location right now. Please try again shortly.'];
        }

        // Prefer a place the model named; otherwise (e.g. device location) label it
        // with the nearest weather station so the card doesn't read "that location".
        $place = trim((string) ($arguments['place'] ?? ''));
        if ($place === '') {
            $place = $this->weather->nearestPlaceLabel($lat, $lon);
        }

        return [
            'place'   => $place !== '' ? $place : 'your location',
            'source'  => $fc['source'] ?? null,
            'issued'  => $fc['issued'],
            'hourly'  => $fc['hourly'],
            'daily'   => $fc['daily'],
            // Interactive weather card (the model still gets the numbers to answer
            // specifics, but should summarise — the card shows the detail visually).
            '_render' => [
                'kind'    => 'weather',
                'title'   => $place !== '' ? $place : null,
                'current' => null,
                'hourly'  => $fc['hourly'],
                'days'    => $fc['daily'],
            ],
        ];
    }
}
