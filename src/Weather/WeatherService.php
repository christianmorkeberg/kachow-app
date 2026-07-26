<?php

declare(strict_types=1);

namespace App\Weather;

/**
 * Weather facade with automatic failover. Prefers DMI (local, high-resolution, and
 * best for Denmark), but transparently falls back to Open-Meteo when DMI is
 * rate-limited/erroring, or when the location is outside DMI's Denmark-only reach.
 * The tools talk only to this — they never see which source answered.
 */
final class WeatherService
{
    private const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    public function __construct(
        private Dmi $dmi,
        private OpenMeteo $openMeteo,
    ) {
    }

    public static function fromEnv(?Dmi $dmi = null, ?OpenMeteo $openMeteo = null): self
    {
        return new self($dmi ?? Dmi::fromEnv(), $openMeteo ?? new OpenMeteo());
    }

    /**
     * Normalized current conditions from the nearest DMI station, or Open-Meteo as
     * a fallback. Null only if BOTH sources fail.
     *
     * @return array<string, mixed>|null
     */
    public function current(float $lat, float $lon): ?array
    {
        try {
            foreach ($this->dmi->nearbyStations($lat, $lon) as $station) {
                $obs = $this->dmi->latestObservations($station['id']);
                if ($obs !== []) {
                    return $this->fromDmiObs($station, $obs);
                }
            }
        } catch (\Throwable $e) {
            error_log('WeatherService: DMI current failed, falling back — ' . $e->getMessage());
        }

        try {
            return $this->openMeteo->current($lat, $lon);
        } catch (\Throwable $e) {
            error_log('WeatherService: Open-Meteo current failed — ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Forecast in Dmi::forecast() shape (with an added `source`), from DMI or the
     * Open-Meteo fallback. [] only if both fail / neither has data.
     *
     * @return array<string, mixed>
     */
    public function forecast(float $lat, float $lon): array
    {
        try {
            $fc = $this->dmi->forecast($lat, $lon);
            if ($fc !== []) {
                $fc['source'] = 'dmi';

                return $fc;
            }
        } catch (\Throwable $e) {
            error_log('WeatherService: DMI forecast failed, falling back — ' . $e->getMessage());
        }

        try {
            $fc = $this->openMeteo->forecast($lat, $lon);
            if ($fc !== []) {
                $fc['source'] = 'open-meteo';

                return $fc;
            }
        } catch (\Throwable $e) {
            error_log('WeatherService: Open-Meteo forecast failed — ' . $e->getMessage());
        }

        return [];
    }

    /** Nearest DMI station name for a nice card label, or '' (best-effort). */
    public function nearestPlaceLabel(float $lat, float $lon): string
    {
        try {
            $stations = $this->dmi->nearbyStations($lat, $lon, 1);

            return $stations !== [] ? (string) $stations[0]['name'] : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array{id:string, name:string, distance_km:float} $station
     * @param array<string, array{value:float, observed:string}> $obs
     * @return array<string, mixed>
     */
    private function fromDmiObs(array $station, array $obs): array
    {
        $val = static fn (string $p) => isset($obs[$p]) ? $obs[$p]['value'] : null;
        $dir = $val('wind_dir');

        return [
            'source'       => 'dmi',
            'place'        => $station['name'],
            'station'      => $station['name'],
            'distance_km'  => $station['distance_km'],
            'temp_c'       => $val('temp_dry'),
            'precip_mm'    => $val('precip_past1h'),
            'humidity_pct' => $val('humidity'),
            'wind_ms'      => $val('wind_speed'),
            'wind_from'    => $dir !== null ? self::compass((float) $dir) : null,
            'pressure_hpa' => $val('pressure_at_sea'),
            'observed'     => $obs['temp_dry']['observed'] ?? (reset($obs)['observed'] ?? null),
        ];
    }

    private static function compass(float $deg): string
    {
        return self::COMPASS[(int) round((fmod($deg, 360)) / 45) % 8];
    }
}
