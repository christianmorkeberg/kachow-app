<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * Raw positions from the user's phone (OwnTracks, HTTP mode → api/owntracks.php). Phase 1
 * of location tracking (see kachow-docs/location-tracking-spec.md): collect, keep 60 days,
 * and show a day on the map. We only ever use raw points — never the app's own region or
 * transition events — and interpret movement ourselves in later phases.
 *
 * Private per user: there is deliberately no connection scope for this data. Coordinates
 * go to the map card only; what the model sees is day statistics without coordinates.
 * recorded_at is UTC; days are Europe/Copenhagen local days (like WorkEvents).
 */
final class LocationPoints
{
    public const RETENTION_DAYS = 60;

    /** Fixes worse than this (metres) are kept but left out of distances / shown faded. */
    public const GOOD_ACC_M = 100;

    /** At most this many points go into one map card (evenly thinned beyond it). */
    private const CARD_MAX_POINTS = 2500;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Normalises one OwnTracks message into a point, or null if it isn't a usable location
     * (other _types — transition, waypoint, lwt, status… — are ignored by design).
     *
     * @param array<string, mixed> $m
     * @return array{recorded_at:string, lat:float, lon:float, acc:?int, alt:?int, vel:?int, cog:?int, batt:?int, conn:?string, trig:?string}|null
     */
    public static function fromOwnTracks(array $m, ?int $now = null): ?array
    {
        if (($m['_type'] ?? null) !== 'location') {
            return null;
        }
        if (!is_numeric($m['lat'] ?? null) || !is_numeric($m['lon'] ?? null) || !is_numeric($m['tst'] ?? null)) {
            return null;
        }
        $lat = (float) $m['lat'];
        $lon = (float) $m['lon'];
        $tst = (int) $m['tst'];
        $now ??= time();
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0.0 && $lon == 0.0)) {
            return null;
        }
        // A fix from the future (clock skew beyond a day) or older than we keep is useless.
        if ($tst > $now + 86400 || $tst < $now - self::RETENTION_DAYS * 86400) {
            return null;
        }
        $int = static fn (string $k, int $min, int $max): ?int =>
            is_numeric($m[$k] ?? null) ? max($min, min($max, (int) round((float) $m[$k]))) : null;
        $chr = static fn (string $k): ?string =>
            is_string($m[$k] ?? null) && $m[$k] !== '' ? mb_substr($m[$k], 0, 1) : null;

        return [
            'recorded_at' => gmdate('Y-m-d H:i:s', $tst),
            'lat'         => round($lat, 6),
            'lon'         => round($lon, 6),
            'acc'         => $int('acc', 0, 65535),
            'alt'         => $int('alt', -32768, 32767),
            'vel'         => $int('vel', 0, 65535),
            'cog'         => $int('cog', 0, 360),
            'batt'        => $int('batt', 0, 100),
            'conn'        => $chr('conn'),
            'trig'        => $chr('t'),
        ];
    }

    /**
     * Stores one point. Returns 'ok', or 'duplicate' when the phone re-sends a fix it
     * already delivered (same device + timestamp — OwnTracks retries after timeouts).
     *
     * @param array{recorded_at:string, lat:float, lon:float, acc:?int, alt:?int, vel:?int, cog:?int, batt:?int, conn:?string, trig:?string} $p
     */
    public function add(int $userId, string $device, array $p): string
    {
        try {
            $this->db->prepare(
                'INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc, alt, vel, cog, batt, conn, trig)
                 VALUES (:u, :d, :at, :lat, :lon, :acc, :alt, :vel, :cog, :batt, :conn, :trig)'
            )->execute([
                ':u' => $userId, ':d' => mb_substr($device, 0, 32), ':at' => $p['recorded_at'],
                ':lat' => $p['lat'], ':lon' => $p['lon'], ':acc' => $p['acc'], ':alt' => $p['alt'],
                ':vel' => $p['vel'], ':cog' => $p['cog'], ':batt' => $p['batt'], ':conn' => $p['conn'],
                ':trig' => $p['trig'],
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') { // unique (user, device, recorded_at)
                return 'duplicate';
            }
            throw $e;
        }

        return 'ok';
    }

    /** Deletes this user's points older than the retention window. Returns rows removed. */
    public function purgeOld(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM location_points WHERE user_id = :u AND recorded_at < :cut');
        $stmt->execute([':u' => $userId, ':cut' => gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)]);

        return $stmt->rowCount();
    }

    /** When the latest point arrived and was taken (UTC), or null if none yet. */
    public function latest(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT recorded_at, device, batt FROM location_points WHERE user_id = :u ORDER BY recorded_at DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $r = $stmt->fetch();

        return $r === false ? null : [
            'recorded_at' => (string) $r['recorded_at'],
            'device'      => (string) $r['device'],
            'batt'        => $r['batt'] !== null ? (int) $r['batt'] : null,
        ];
    }

    /**
     * One local day: statistics (for the model — no coordinates) and a map card (for the UI).
     *
     * @return array{stats: array<string, mixed>, card: array<string, mixed>}
     */
    public function day(int $userId, string $localDate): array
    {
        $tz    = new DateTimeZone(WorkEvents::LOCAL_TZ);
        $utc   = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($localDate, $tz))->setTime(0, 0);
        $end   = $start->modify('+1 day');

        $stmt = $this->db->prepare(
            'SELECT recorded_at, lat, lon, acc, vel, batt FROM location_points
             WHERE user_id = :u AND recorded_at >= :f AND recorded_at < :t
             ORDER BY recorded_at ASC'
        );
        $stmt->execute([
            ':u' => $userId,
            ':f' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            ':t' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll();

        $pts = [];
        foreach ($rows as $r) {
            $at    = new DateTimeImmutable((string) $r['recorded_at'], $utc);
            $pts[] = [
                'ts'   => $at->getTimestamp(),
                'time' => $at->setTimezone($tz)->format('H:i'),
                'lat'  => (float) $r['lat'],
                'lon'  => (float) $r['lon'],
                'acc'  => $r['acc'] !== null ? (int) $r['acc'] : null,
                'vel'  => $r['vel'] !== null ? (int) $r['vel'] : null,
                'batt' => $r['batt'] !== null ? (int) $r['batt'] : null,
            ];
        }

        $label = $start->format('D j M');
        $stats = self::stats($pts);
        $stats = ['date' => $start->format('Y-m-d'), 'day' => $label] + $stats;

        return [
            'stats' => $stats,
            'card'  => [
                'kind'   => 'location_day',
                'date'   => $start->format('Y-m-d'),
                'title'  => $label,
                'stats'  => $stats,
                'good_acc_m' => self::GOOD_ACC_M,
                // Coordinates are never stored with the chat history (see AssistantLoop::lastRenderJson).
                '_persist_strip' => ['points'],
                // [lat, lon, "HH:MM", acc|null, km/h|null]
                'points' => array_map(
                    static fn (array $p): array => [$p['lat'], $p['lon'], $p['time'], $p['acc'], $p['vel']],
                    self::thin($pts, self::CARD_MAX_POINTS)
                ),
            ],
        ];
    }

    /**
     * Data-quality statistics for a day's points (ordered by time).
     *
     * @param list<array{ts:int, time:string, lat:float, lon:float, acc:?int, vel:?int, batt:?int}> $pts
     * @return array<string, mixed>
     */
    public static function stats(array $pts): array
    {
        $n = count($pts);
        if ($n === 0) {
            return ['points' => 0];
        }

        $km = 0.0;
        $prevGood = null;
        $gap = ['minutes' => 0, 'from' => null, 'to' => null];
        $accs = [];
        $poor = 0;
        foreach ($pts as $i => $p) {
            $good = $p['acc'] === null || $p['acc'] <= self::GOOD_ACC_M;
            if ($p['acc'] !== null) {
                $accs[] = $p['acc'];
            }
            if (!$good) {
                $poor++;
            } else {
                if ($prevGood !== null) {
                    $km += self::haversineKm($prevGood['lat'], $prevGood['lon'], $p['lat'], $p['lon']);
                }
                $prevGood = $p;
            }
            if ($i > 0) {
                $mins = (int) round(($p['ts'] - $pts[$i - 1]['ts']) / 60);
                if ($mins > $gap['minutes']) {
                    $gap = ['minutes' => $mins, 'from' => $pts[$i - 1]['time'], 'to' => $p['time']];
                }
            }
        }
        sort($accs);
        $batts = array_values(array_filter(array_column($pts, 'batt'), static fn ($b): bool => $b !== null));

        $spanMin = (int) round(($pts[$n - 1]['ts'] - $pts[0]['ts']) / 60);

        return [
            'points'           => $n,
            'first'            => $pts[0]['time'],
            'last'             => $pts[$n - 1]['time'],
            'span_minutes'     => $spanMin,
            'avg_interval_min' => $n > 1 ? round($spanMin / ($n - 1), 1) : null,
            'distance_km'      => round($km, 1),
            'largest_gap'      => $n > 1 ? $gap : null,
            'median_acc_m'     => $accs !== [] ? $accs[intdiv(count($accs), 2)] : null,
            'poor_acc_points'  => $poor,
            'battery'          => $batts !== [] ? ['from' => $batts[0], 'to' => $batts[count($batts) - 1]] : null,
        ];
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r    = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Evenly thins a list to at most $max items, always keeping the first and last.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    private static function thin(array $items, int $max): array
    {
        $n = count($items);
        if ($n <= $max) {
            return $items;
        }
        $out  = [];
        $step = ($n - 1) / ($max - 1);
        for ($i = 0; $i < $max; $i++) {
            $out[] = $items[(int) round($i * $step)];
        }

        return $out;
    }
}
