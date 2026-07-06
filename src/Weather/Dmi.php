<?php

declare(strict_types=1);

namespace App\Weather;

use RuntimeException;

/**
 * Minimal client for DMI's free Meteorological Observation API (metObs).
 *
 * Uses the opendataapi.dmi.dk host, which requires NO authentication. Gives
 * current/recent observations from Danish weather stations. The HTTP transport is
 * injectable (a callable) so the tool is testable without network.
 *
 * Strategy for "weather near me": find the nearest active Synop station (those
 * carry the full suite — temperature, wind, humidity, precipitation, pressure),
 * then read its latest-hour observations.
 */
final class Dmi
{
    private const BASE          = 'https://opendataapi.dmi.dk/v2/metObs';
    private const FORECAST_BASE = 'https://opendataapi.dmi.dk/v1/forecastedr';

    /** The observation parameters we surface, in report order. */
    public const PARAMS = [
        'temp_dry', 'temp_max_past1h', 'temp_min_past1h',
        'precip_past1h', 'humidity', 'wind_speed', 'wind_dir', 'pressure_at_sea',
    ];

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    /** How many times to attempt a request before giving up (DMI rate-limits often). */
    private int $maxAttempts;

    public function __construct(?callable $transport = null, int $maxAttempts = 3)
    {
        $this->transport  = $transport ?? [$this, 'curlGet'];
        $this->maxAttempts = max(1, $maxAttempts);
    }

    public static function fromEnv(?callable $transport = null): self
    {
        return new self($transport);
    }

    /**
     * Active Synop stations near a point, nearest first, within $maxKm (so foreign
     * locations return none — DMI is Denmark only). Returns [] if none in range.
     * The caller tries them in order, since a given station may have no current data.
     *
     * @return array<int, array{id:string, name:string, lat:float, lon:float, distance_km:float}>
     */
    public function nearbyStations(float $lat, float $lon, int $max = 5, float $maxKm = 80.0): array
    {
        foreach ([0.5, 1.0, 2.0] as $d) {
            $bbox = sprintf('%.4f,%.4f,%.4f,%.4f', $lon - $d, $lat - $d * 0.65, $lon + $d, $lat + $d * 0.65);
            $data = $this->get('/collections/station/items', [
                'type'   => 'Synop',
                'status' => 'Active',
                'bbox'   => $bbox,
            ]);

            $cands = [];
            foreach ($data['features'] ?? [] as $f) {
                $c  = $f['geometry']['coordinates'] ?? null;
                $id = (string) ($f['properties']['stationId'] ?? '');
                if ($id === '' || !is_array($c) || count($c) < 2 || isset($cands[$id])) {
                    continue;
                }
                $dist = self::haversineKm($lat, $lon, (float) $c[1], (float) $c[0]);
                if ($dist <= $maxKm) {
                    $cands[$id] = [
                        'id'          => $id,
                        'name'        => (string) ($f['properties']['name'] ?? ''),
                        'lat'         => (float) $c[1],
                        'lon'         => (float) $c[0],
                        'distance_km' => round($dist, 1),
                    ];
                }
            }

            if ($cands !== []) {
                $cands = array_values($cands);
                usort($cands, static fn (array $a, array $b): int => $a['distance_km'] <=> $b['distance_km']);
                return array_slice($cands, 0, $max);
            }
        }

        return [];
    }

    /**
     * Latest value per parameter for a station over the past hour.
     *
     * @param array<int,string> $params
     * @return array<string, array{value:float, observed:string}>
     */
    public function latestObservations(string $stationId, array $params = self::PARAMS): array
    {
        $data = $this->get('/collections/observation/items', [
            'stationId' => $stationId,
            'period'    => 'latest-hour',
            'sortorder' => 'observed,DESC', // newest first, so first seen per param is latest
            'limit'     => 300,
        ]);

        $out = [];
        foreach ($data['features'] ?? [] as $f) {
            $p   = $f['properties'] ?? [];
            $pid = (string) ($p['parameterId'] ?? '');
            if ($pid === '' || isset($out[$pid])) {
                continue;
            }
            if ($params !== [] && !in_array($pid, $params, true)) {
                continue;
            }
            $out[$pid] = ['value' => (float) ($p['value'] ?? 0), 'observed' => (string) ($p['observed'] ?? '')];
        }

        return $out;
    }

    /**
     * Point forecast (HARMONIE DINI surface model) for the coming ~2.5 days,
     * summarised into a few hourly steps + per-day aggregates. Times are local
     * (Europe/Copenhagen). Temperature is converted K→°C, cloud fraction→%, and
     * precipitation is the per-step delta of the accumulated total. [] if the point
     * has no forecast (outside the model domain).
     *
     * @return array{issued:string, hourly:array<int,array<string,mixed>>, daily:array<int,array<string,mixed>>}|array{}
     */
    public function forecast(float $lat, float $lon, int $days = 3, int $hourlyStepH = 3, int $hourlyCount = 8): array
    {
        // Only the parameters the card/summary actually uses — wind direction was
        // fetched but never surfaced, and every extra parameter enlarges DMI's
        // (already slow) response, so it's dropped to help latency.
        $params = ['temperature-2m', 'total-precipitation', 'wind-speed-10m', 'fraction-of-cloud-cover'];
        // Build the query by hand: EDR wants coords=POINT(lon lat) with a %20 space,
        // and plain commas in parameter-name (http_build_query would mangle both).
        $url = self::FORECAST_BASE . '/collections/harmonie_dini_sf/position?coords=POINT('
            . sprintf('%.4f', $lon) . '%20' . sprintf('%.4f', $lat) . ')'
            . '&parameter-name=' . implode(',', $params)
            . '&crs=crs84';

        $data   = $this->getJson($url);
        $times  = $data['domain']['axes']['t']['values'] ?? [];
        $ranges = $data['ranges'] ?? [];
        if (!is_array($times) || $times === [] || !is_array($ranges) || $ranges === []) {
            return [];
        }

        $temp    = $ranges['temperature-2m']['values'] ?? [];
        $precAcc = $ranges['total-precipitation']['values'] ?? [];
        $wind    = $ranges['wind-speed-10m']['values'] ?? [];
        $cloud   = $ranges['fraction-of-cloud-cover']['values'] ?? [];

        $tz    = new \DateTimeZone('Europe/Copenhagen');
        $steps = [];
        foreach ($times as $i => $iso) {
            $dt       = (new \DateTimeImmutable((string) $iso))->setTimezone($tz);
            $precipHr = ($i > 0 && isset($precAcc[$i], $precAcc[$i - 1])) ? max(0.0, $precAcc[$i] - $precAcc[$i - 1]) : 0.0;
            $steps[] = [
                'dt'        => $dt,
                'temp_c'    => isset($temp[$i]) ? round($temp[$i] - 273.15, 1) : null,
                'precip_mm' => round($precipHr, 2),
                'wind_ms'   => isset($wind[$i]) ? round($wind[$i], 1) : null,
                'cloud_pct' => isset($cloud[$i]) ? (int) round($cloud[$i] * 100) : null,
            ];
        }

        // Hourly: every Nth step, first $hourlyCount of them.
        $hourly = [];
        for ($i = 0; $i < count($steps) && count($hourly) < $hourlyCount; $i += max(1, $hourlyStepH)) {
            $s = $steps[$i];
            $hourly[] = [
                'time'      => $s['dt']->format('Y-m-d H:i'),
                'temp_c'    => $s['temp_c'],
                'precip_mm' => $s['precip_mm'],
                'wind_ms'   => $s['wind_ms'],
                'cloud_pct' => $s['cloud_pct'],
            ];
        }

        // Daily aggregates for the next $days local days.
        $byDay = [];
        foreach ($steps as $s) {
            $byDay[$s['dt']->format('Y-m-d')][] = $s;
        }
        $daily = [];
        foreach ($byDay as $date => $rows) {
            if (count($daily) >= $days) {
                break;
            }
            $temps  = array_values(array_filter(array_column($rows, 'temp_c'), static fn ($v) => $v !== null));
            $winds  = array_values(array_filter(array_column($rows, 'wind_ms'), static fn ($v) => $v !== null));
            $clouds = array_values(array_filter(array_column($rows, 'cloud_pct'), static fn ($v) => $v !== null));
            $daily[] = [
                'date'          => $date,
                'weekday'       => (new \DateTimeImmutable($date, $tz))->format('l'),
                'temp_min_c'    => $temps !== [] ? min($temps) : null,
                'temp_max_c'    => $temps !== [] ? max($temps) : null,
                'precip_mm'     => round(array_sum(array_column($rows, 'precip_mm')), 1),
                'wind_max_ms'   => $winds !== [] ? max($winds) : null,
                'cloud_avg_pct' => $clouds !== [] ? (int) round(array_sum($clouds) / count($clouds)) : null,
            ];
        }

        return ['issued' => $steps[0]['dt']->format('c'), 'hourly' => $hourly, 'daily' => $daily];
    }

    /**
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        return $this->getJson(self::BASE . $path . '?' . http_build_query($query));
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        // DMI's free tier rate-limits (429) and occasionally 503s under load, so
        // retry a few times with a short backoff before surfacing an error. Most
        // rejections clear within a second or two, so this recovers transparently.
        for ($attempt = 1; ; $attempt++) {
            try {
                [$status, $body] = ($this->transport)($url);
            } catch (RuntimeException $e) {
                if ($attempt < $this->maxAttempts) {
                    $this->backoff($attempt);
                    continue; // transient transport failure (timeout, dropped connection)
                }
                throw $e;
            }

            if (($status === 429 || $status === 503) && $attempt < $this->maxAttempts) {
                $this->backoff($attempt);
                continue;
            }
            if ($status === 429 || $status === 503) {
                throw new RuntimeException(
                    'The weather service (DMI) is busy right now and kept rate-limiting the request, '
                    . 'even after retrying. Please try again in a moment.'
                );
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('DMI API error: HTTP ' . $status);
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('DMI API returned invalid JSON.');
            }

            return $decoded;
        }
    }

    /**
     * Backoff between retries: ~0.7s, ~1.4s, … with jitter, bounded. DMI's rate
     * limiter needs more than a few hundred ms of breathing room to clear.
     */
    private function backoff(int $attempt): void
    {
        $ms = 600 * $attempt + random_int(0, 400);
        usleep(min($ms, 2500) * 1000);
    }

    private static function compass(float $deg): string
    {
        $dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

        return $dirs[(int) round((fmod($deg, 360)) / 45) % 8];
    }

    /** @return array{0:int,1:string} [statusCode, body] */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            // The forecast endpoint is legitimately slow (~7-12s healthy), so give a
            // single attempt enough room to finish rather than timing out into DMI's
            // rate limiter. Connect timeout stays short to fail fast on a dead host.
            CURLOPT_TIMEOUT        => 28,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/geo+json'],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('DMI request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
