<?php

declare(strict_types=1);

namespace App\Weather;

use DateTimeImmutable;
use RuntimeException;

/**
 * Backup weather source: Open-Meteo (open-meteo.com). Free, keyless, global, and
 * far less rate-limited than DMI — used as the fallback when DMI is busy or when a
 * location is outside DMI's Denmark-only coverage.
 *
 * Deliberately mirrors Dmi's output shapes so it is a drop-in behind WeatherService:
 * forecast() returns the SAME {issued, hourly[], daily[]} structure, and current()
 * returns the normalized current-conditions array WeatherService expects.
 */
final class OpenMeteo
{
    private const BASE = 'https://api.open-meteo.com/v1/forecast';

    private const COMPASS = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    private int $maxAttempts;

    public function __construct(?callable $transport = null, int $maxAttempts = 2)
    {
        $this->transport   = $transport ?? [$this, 'curlGet'];
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * Normalized current conditions (matching WeatherService's shape), or null if
     * unavailable.
     *
     * @return array<string, mixed>|null
     */
    public function current(float $lat, float $lon): ?array
    {
        $url = self::BASE . '?' . http_build_query([
            'latitude'        => sprintf('%.4f', $lat),
            'longitude'       => sprintf('%.4f', $lon),
            'current'         => 'temperature_2m,relative_humidity_2m,precipitation,'
                . 'wind_speed_10m,wind_direction_10m,surface_pressure,cloud_cover',
            'wind_speed_unit' => 'ms',
            'timezone'        => 'auto',
        ]);

        $data = $this->getJson($url);
        $c    = $data['current'] ?? null;
        if (!is_array($c) || !isset($c['temperature_2m'])) {
            return null;
        }

        $num = static fn (string $k) => isset($c[$k]) && is_numeric($c[$k]) ? (float) $c[$k] : null;
        $windDir = $num('wind_direction_10m');

        return [
            'source'       => 'open-meteo',
            'place'        => '',   // Open-Meteo has no station name; caller labels it
            'station'      => null,
            'temp_c'       => $num('temperature_2m') !== null ? round($num('temperature_2m'), 1) : null,
            'precip_mm'    => $num('precipitation'),
            'humidity_pct' => $num('relative_humidity_2m') !== null ? (int) round($num('relative_humidity_2m')) : null,
            'wind_ms'      => $num('wind_speed_10m') !== null ? round($num('wind_speed_10m'), 1) : null,
            'wind_from'    => $windDir !== null ? self::compass($windDir) : null,
            'pressure_hpa' => $num('surface_pressure') !== null ? round($num('surface_pressure'), 1) : null,
            'observed'     => isset($c['time']) ? (string) $c['time'] : null,
        ];
    }

    /**
     * Point forecast in the SAME shape as Dmi::forecast(): a few hourly steps + a
     * per-day summary, local time. [] if the response has no usable series.
     *
     * @return array{issued:string, hourly:array<int,array<string,mixed>>, daily:array<int,array<string,mixed>>}|array{}
     */
    public function forecast(float $lat, float $lon, int $days = 3, int $hourlyStepH = 3, int $hourlyCount = 8): array
    {
        $url = self::BASE . '?' . http_build_query([
            'latitude'        => sprintf('%.4f', $lat),
            'longitude'       => sprintf('%.4f', $lon),
            'hourly'          => 'temperature_2m,precipitation,wind_speed_10m,cloud_cover',
            'wind_speed_unit' => 'ms',
            'timezone'        => 'auto',
            'forecast_days'   => max(1, min($days + 1, 7)),   // +1 so the last local day is complete
        ]);

        $data  = $this->getJson($url);
        $h     = $data['hourly'] ?? [];
        $times = $h['time'] ?? [];
        if (!is_array($times) || $times === []) {
            return [];
        }
        $temp  = $h['temperature_2m'] ?? [];
        $prec  = $h['precipitation'] ?? [];   // already per-hour (not accumulated) — unlike DMI
        $wind  = $h['wind_speed_10m'] ?? [];
        $cloud = $h['cloud_cover'] ?? [];

        // Open-Meteo returns local wall-clock ISO strings (timezone=auto), e.g.
        // "2026-07-26T14:00" — treat them as local and format like Dmi does.
        $steps = [];
        foreach ($times as $i => $iso) {
            $steps[] = [
                'iso'       => (string) $iso,
                'temp_c'    => isset($temp[$i]) && is_numeric($temp[$i]) ? round((float) $temp[$i], 1) : null,
                'precip_mm' => isset($prec[$i]) && is_numeric($prec[$i]) ? round((float) $prec[$i], 2) : 0.0,
                'wind_ms'   => isset($wind[$i]) && is_numeric($wind[$i]) ? round((float) $wind[$i], 1) : null,
                'cloud_pct' => isset($cloud[$i]) && is_numeric($cloud[$i]) ? (int) round((float) $cloud[$i]) : null,
            ];
        }

        // Skip past hours so "hourly" starts around now (the series begins at midnight).
        $startIdx = 0;
        $nowLocal = (new DateTimeImmutable('now'))->format('Y-m-d\TH:i');
        foreach ($steps as $i => $s) {
            if (substr($s['iso'], 0, 13) >= substr($nowLocal, 0, 13)) {
                $startIdx = $i;
                break;
            }
        }

        $hourly = [];
        for ($i = $startIdx; $i < count($steps) && count($hourly) < $hourlyCount; $i += max(1, $hourlyStepH)) {
            $s = $steps[$i];
            $hourly[] = [
                'time'      => str_replace('T', ' ', substr($s['iso'], 0, 16)),
                'temp_c'    => $s['temp_c'],
                'precip_mm' => $s['precip_mm'],
                'wind_ms'   => $s['wind_ms'],
                'cloud_pct' => $s['cloud_pct'],
            ];
        }

        // Daily aggregates for the next $days local days (grouped by date).
        $byDay = [];
        foreach ($steps as $s) {
            $byDay[substr($s['iso'], 0, 10)][] = $s;
        }
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $daily = [];
        foreach ($byDay as $date => $rows) {
            if ($date < $today) {
                continue;
            }
            if (count($daily) >= $days) {
                break;
            }
            $temps  = array_values(array_filter(array_column($rows, 'temp_c'), static fn ($v) => $v !== null));
            $winds  = array_values(array_filter(array_column($rows, 'wind_ms'), static fn ($v) => $v !== null));
            $clouds = array_values(array_filter(array_column($rows, 'cloud_pct'), static fn ($v) => $v !== null));
            $daily[] = [
                'date'          => $date,
                'weekday'       => (new DateTimeImmutable($date))->format('l'),
                'temp_min_c'    => $temps !== [] ? min($temps) : null,
                'temp_max_c'    => $temps !== [] ? max($temps) : null,
                'precip_mm'     => round(array_sum(array_column($rows, 'precip_mm')), 1),
                'wind_max_ms'   => $winds !== [] ? max($winds) : null,
                'cloud_avg_pct' => $clouds !== [] ? (int) round(array_sum($clouds) / count($clouds)) : null,
            ];
        }

        if ($daily === [] && $hourly === []) {
            return [];
        }

        $issued = str_replace('T', ' ', substr($steps[$startIdx]['iso'] ?? (string) $times[0], 0, 16));

        return ['issued' => $issued, 'hourly' => $hourly, 'daily' => $daily];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                [$status, $body] = ($this->transport)($url);
            } catch (RuntimeException $e) {
                if ($attempt < $this->maxAttempts) {
                    usleep(400_000);
                    continue;
                }
                throw $e;
            }

            if (($status === 429 || $status >= 500) && $attempt < $this->maxAttempts) {
                usleep(400_000);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Open-Meteo API error: HTTP ' . $status);
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Open-Meteo returned invalid JSON.');
            }

            return $decoded;
        }
    }

    private static function compass(float $deg): string
    {
        return self::COMPASS[(int) round((fmod($deg, 360)) / 45) % 8];
    }

    /** @return array{0:int,1:string} [statusCode, body] */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Open-Meteo request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
