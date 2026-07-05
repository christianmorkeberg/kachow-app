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
    private const BASE = 'https://opendataapi.dmi.dk/v2/metObs';

    /** The observation parameters we surface, in report order. */
    public const PARAMS = [
        'temp_dry', 'temp_max_past1h', 'temp_min_past1h',
        'precip_past1h', 'humidity', 'wind_speed', 'wind_dir', 'pressure_at_sea',
    ];

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'curlGet'];
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
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        $url = self::BASE . $path . '?' . http_build_query($query);
        [$status, $body] = ($this->transport)($url);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('DMI API error: HTTP ' . $status);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('DMI API returned invalid JSON.');
        }

        return $decoded;
    }

    /** @return array{0:int,1:string} [statusCode, body] */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
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
