<?php

declare(strict_types=1);

namespace App\Tools;

use App\Weather\Dmi;

/**
 * Tool: weather FORECAST for the coming hours/days from DMI's HARMONIE model.
 * Complements get_current_weather (which is observations right now).
 */
final class GetWeatherForecast implements Tool
{
    public function __construct(private Dmi $dmi)
    {
    }

    public function name(): string
    {
        return 'get_weather_forecast';
    }

    public function description(): string
    {
        return 'Gets the weather FORECAST for the next ~2.5 days from DMI (temperature, rain, wind, '
            . 'cloud cover) — as a few upcoming hourly steps plus a per-day summary. Use for anything '
            . 'about the future: later today, tonight, tomorrow, the weekend. For conditions right now, '
            . 'use get_current_weather instead. Provide latitude and longitude — the user\'s device '
            . 'location if given, otherwise the place they mention. Covers Denmark and nearby. Times '
            . 'are local (Denmark).';
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

        $fc = $this->dmi->forecast($lat, $lon);
        if ($fc === []) {
            return ['error' => 'No forecast is available for that location (DMI covers Denmark and nearby).'];
        }

        // Prefer a place the model named; otherwise (e.g. device location) label it
        // with the nearest weather station so the card doesn't read "that location".
        $place = trim((string) ($arguments['place'] ?? ''));
        if ($place === '') {
            $place = $this->nearestPlace($lat, $lon);
        }

        return [
            'place'   => $place !== '' ? $place : 'your location',
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

    /** Nearest station name for a nice card label, or '' if unavailable. */
    private function nearestPlace(float $lat, float $lon): string
    {
        try {
            $stations = $this->dmi->nearbyStations($lat, $lon, 1);

            return $stations !== [] ? (string) $stations[0]['name'] : '';
        } catch (\Throwable $e) {
            return ''; // never let labelling break the forecast
        }
    }
}
