<?php

declare(strict_types=1);

namespace App\Maps;

use RuntimeException;

/**
 * Address → driving distance via OpenRouteService (openrouteservice.org).
 *
 * Two hops: geocode each address to coordinates, then a driving-car route between
 * them. Returns the one-way and round-trip distance in km so the mileage feature can
 * pre-fill a destination's distance from a home + workplace address. The API key is a
 * server-side secret ($_ENV['ORS_API_KEY'], never exposed to the client); this class
 * only runs behind the authenticated web app.
 *
 * Mirrors the OpenMeteo shape: an injectable $transport callable (curl by default)
 * makes it unit-testable with a fake, and the lookup fails soft — the caller only ever
 * uses the number to PRE-FILL a field the user then confirms, so a bad geocode can
 * never silently corrupt a deduction.
 */
final class MapDistance
{
    private const GEOCODE   = 'https://api.openrouteservice.org/geocode/search';
    private const DIRECTIONS = 'https://api.openrouteservice.org/v2/directions/driving-car';

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    private string $apiKey;

    private int $maxAttempts;

    public function __construct(?string $apiKey = null, ?callable $transport = null, int $maxAttempts = 2)
    {
        $this->apiKey      = $apiKey ?? (string) ($_ENV['ORS_API_KEY'] ?? '');
        $this->transport   = $transport ?? [$this, 'curlGet'];
        $this->maxAttempts = max(1, $maxAttempts);
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Driving distance between two addresses.
     *
     * @return array{one_way_km:float, round_trip_km:float, from:string, to:string}
     * @throws RuntimeException when not configured, an address can't be found, or the
     *         routing call fails.
     */
    public function lookup(string $from, string $to): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Map lookup is not configured (no ORS_API_KEY).');
        }
        $from = trim($from);
        $to   = trim($to);
        if ($from === '' || $to === '') {
            throw new RuntimeException('Both a home and a destination address are needed.');
        }

        [$fromLon, $fromLat, $fromLabel] = $this->geocode($from);
        [$toLon, $toLat, $toLabel]       = $this->geocode($to);

        $url = self::DIRECTIONS . '?' . http_build_query([
            'api_key' => $this->apiKey,
            'start'   => $fromLon . ',' . $fromLat,
            'end'     => $toLon . ',' . $toLat,
        ]);
        $data     = $this->getJson($url);
        $features = $data['features'] ?? null;
        $meters   = null;
        if (is_array($features) && isset($features[0]['properties']['summary']['distance'])) {
            $meters = (float) $features[0]['properties']['summary']['distance'];
        }
        if ($meters === null || $meters <= 0) {
            throw new RuntimeException('No driving route found between those addresses.');
        }

        $oneWay = round($meters / 1000, 1);

        return [
            'one_way_km'    => $oneWay,
            'round_trip_km' => round($oneWay * 2, 1),
            'from'          => $fromLabel,
            'to'            => $toLabel,
        ];
    }

    /**
     * @return array{0:float, 1:float, 2:string} [lon, lat, label]
     */
    private function geocode(string $address): array
    {
        $url = self::GEOCODE . '?' . http_build_query([
            'api_key' => $this->apiKey,
            'text'    => $address,
            'size'    => 1,
        ]);
        $data     = $this->getJson($url);
        $features = $data['features'] ?? null;
        if (!is_array($features) || !isset($features[0]['geometry']['coordinates'][0], $features[0]['geometry']['coordinates'][1])) {
            throw new RuntimeException('Could not find that address: ' . $address);
        }
        $coords = $features[0]['geometry']['coordinates'];
        $label  = isset($features[0]['properties']['label']) ? (string) $features[0]['properties']['label'] : $address;

        return [(float) $coords[0], (float) $coords[1], $label];
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
                throw new RuntimeException('OpenRouteService error: HTTP ' . $status);
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('OpenRouteService returned invalid JSON.');
            }

            return $decoded;
        }
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
            throw new RuntimeException('OpenRouteService request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
