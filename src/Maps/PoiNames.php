<?php

declare(strict_types=1);

namespace App\Maps;

use App\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Best-effort "what's probably here" lookup for an unnamed place the user keeps
 * returning to (dev idea #38) — e.g. a supermarket car park becomes a suggested name
 * "Netto" instead of a blank field. Reverse-geocodes via the public Nominatim API
 * (OpenStreetMap; free, no API key — unlike the ORS-backed mileage lookups) and ONLY
 * returns a name when the matched feature is itself a POI (shop/amenity/etc.), not a
 * bare road or address, so a car park with nothing nearby stays blank rather than
 * suggesting a street name nobody would recognise.
 *
 * Results are cached PERMANENTLY per ~11m cell (see migrations/2026-10-16_poi_name_cache.sql)
 * — Nominatim's usage policy requires caching and caps lookups at 1/second; this class also
 * self-paces real network calls to stay under that, so a cold cache with several unnamed
 * places doesn't burst requests. No expiry: this only ever feeds a one-time suggestion for a
 * place the user hasn't named yet — once they name (and so save) it, this cache stops being
 * consulted for that spot at all, so re-verifying a shop hasn't renamed in the meantime buys
 * nothing. (Also sidesteps comparing a cached timestamp against "now" for freshness, which
 * would otherwise need the same MySQL-side NOW()/UTC_TIMESTAMP() care the rest of the app
 * uses for timestamp columns, since a plain DELETE/no-expiry needs no such comparison at all.)
 */
final class PoiNames
{
    private const REVERSE = 'https://nominatim.openstreetmap.org/reverse';

    /** Nominatim's top-level `category` values worth suggesting as a place name. */
    private const USEFUL_CATEGORIES = ['shop', 'amenity', 'tourism', 'leisure', 'office', 'craft', 'healthcare'];

    /** @var callable(string):array{0:int,1:string} */
    private $transport;

    private PDO $db;

    /** Only the real network path paces itself — an injected (test) transport isn't Nominatim. */
    private bool $paced;

    /** Across calls within one request, so a cold cache of several lookups self-paces. */
    private static ?float $lastFetchAt = null;

    public function __construct(?PDO $db = null, ?callable $transport = null)
    {
        $this->db        = $db ?? Database::get();
        $this->transport = $transport ?? [$this, 'curlGet'];
        $this->paced     = $transport === null;
    }

    /**
     * A best-effort POI name near a point, or null if nothing POI-like is there (or the
     * lookup fails) — never throws, since this only ever PRE-FILLS a field the user
     * reviews and can clear or overwrite before saving.
     */
    public function nameNear(float $lat, float $lon): ?string
    {
        $latKey = round($lat, 4);
        $lonKey = round($lon, 4);

        $cached = $this->cached($latKey, $lonKey);
        if ($cached !== false) {
            return $cached;
        }

        $name = $this->fetch($lat, $lon);
        $this->store($latKey, $lonKey, $name);

        return $name;
    }

    /** @return string|null|false false = no cache row yet; null = cached as "no POI here" */
    private function cached(float $latKey, float $lonKey): string|null|false
    {
        $stmt = $this->db->prepare('SELECT name FROM poi_name_cache WHERE lat_key = :lat AND lon_key = :lon');
        $stmt->execute([':lat' => $latKey, ':lon' => $lonKey]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }

        return $row['name'] === null ? null : (string) $row['name'];
    }

    /**
     * Update-then-insert-if-missing rather than an UPSERT: portable across MySQL (production)
     * and SQLite (bin/poi-names-test.php) with no dialect-specific syntax either engine
     * rejects. A duplicate-key race on the INSERT (two first-time lookups of the same brand
     * new cell) is caught and ignored — the row ends up with a value either way.
     */
    private function store(float $latKey, float $lonKey, ?string $name): void
    {
        $upd = $this->db->prepare(
            'UPDATE poi_name_cache SET name = :name, fetched_at = CURRENT_TIMESTAMP WHERE lat_key = :lat AND lon_key = :lon'
        );
        $upd->execute([':name' => $name, ':lat' => $latKey, ':lon' => $lonKey]);
        if ($upd->rowCount() === 0) {
            try {
                $this->db->prepare('INSERT INTO poi_name_cache (lat_key, lon_key, name) VALUES (:lat, :lon, :name)')
                    ->execute([':lat' => $latKey, ':lon' => $lonKey, ':name' => $name]);
            } catch (\PDOException $e) {
                // Lost the race to a concurrent first-time lookup of the same cell — fine,
                // it already has a value.
            }
        }
    }

    private function fetch(float $lat, float $lon): ?string
    {
        if ($this->paced) {
            $this->pace();
        }

        $url = self::REVERSE . '?' . http_build_query([
            'format'         => 'jsonv2',
            'lat'            => $lat,
            'lon'            => $lon,
            'zoom'           => 18,
            'addressdetails' => 1,
        ]);

        try {
            [$status, $body] = ($this->transport)($url);
        } catch (Throwable $e) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $name     = trim((string) ($data['name'] ?? ''));
        $category = (string) ($data['category'] ?? '');
        if ($name === '' || !in_array($category, self::USEFUL_CATEGORIES, true)) {
            return null;
        }

        return mb_substr($name, 0, 120);
    }

    /** Nominatim's public instance caps lookups at 1/second; self-pace real fetches. */
    private function pace(): void
    {
        if (self::$lastFetchAt !== null) {
            $elapsed = microtime(true) - self::$lastFetchAt;
            if ($elapsed < 1.05) {
                usleep((int) round((1.05 - $elapsed) * 1_000_000));
            }
        }
        self::$lastFetchAt = microtime(true);
    }

    /** @return array{0:int,1:string} [statusCode, body] */
    private function curlGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Nominatim's usage policy requires a descriptive User-Agent (a stock HTTP
            // library default is explicitly rejected) — same identifier as MapDistance.
            CURLOPT_USERAGENT      => 'Kachow/1.0 (+https://assistant.kachow.dk)',
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Nominatim request failed: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $body];
    }
}
