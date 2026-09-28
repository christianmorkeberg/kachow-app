<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * The user's own named places — drawn on the map in the app (or saved from their current
 * position), never predefined in code. Location tracking phase 2 (see kachow-docs/
 * location-tracking-spec.md). A place is a circle (centre + radius) or a polygon; its type
 * decides what it drives later (work → work clock, business/commute → kørebog).
 *
 * Also computes the first, deliberately simple "time in places" for a day: consecutive
 * accurate points inside a place form a visit. The proper arrival/leave rules (dwell time,
 * same-day bridging, drive-bys) are phase 3/4.
 */
final class Places
{
    public const TYPES = ['work', 'business', 'commute', 'home', 'private', 'other'];

    public const MIN_RADIUS_M = 25;
    public const MAX_RADIUS_M = 2000;
    private const MAX_VERTICES = 60;

    /** A visit shorter than this is a pass-by (e.g. driving past), counted separately. */
    public const MIN_VISIT_MIN = 5;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return list<array<string, mixed>> the user's places, by name */
    public function list(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, type, shape, lat, lon, radius_m, polygon FROM places WHERE user_id = :u ORDER BY name'
        );
        $stmt->execute([':u' => $userId]);

        return array_map([$this, 'row'], $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, type, shape, lat, lon, radius_m, polygon FROM places WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();

        return $r === false ? null : $this->row($r);
    }

    /** Case-insensitive lookup by name (what the user calls it). */
    public function findByName(int $userId, string $name): ?array
    {
        $key = mb_strtolower(trim($name));
        foreach ($this->list($userId) as $p) {
            if (mb_strtolower($p['name']) === $key) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Creates a place. $data: name, type, and either lat/lon/radius_m (circle) or polygon
     * [[lat, lon], …] (≥ 3 vertices). Throws InvalidArgumentException with a user-facing
     * message on bad input or a duplicate name.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> the stored place
     */
    public function add(int $userId, array $data): array
    {
        $v = $this->validate($data);
        $this->assertNameFree($userId, $v['name'], null);
        try {
            $this->db->prepare(
                'INSERT INTO places (user_id, name, type, shape, lat, lon, radius_m, polygon)
                 VALUES (:u, :n, :t, :s, :lat, :lon, :r, :poly)'
            )->execute([
                ':u' => $userId, ':n' => $v['name'], ':t' => $v['type'], ':s' => $v['shape'], ':lat' => $v['lat'],
                ':lon' => $v['lon'], ':r' => $v['radius_m'], ':poly' => $v['polygon'],
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new InvalidArgumentException('You already have a place called "' . $v['name'] . '".');
            }
            throw $e;
        }

        return (array) $this->get($userId, (int) $this->db->lastInsertId());
    }

    /**
     * Updates a place: any of name, type, radius_m, or a new shape (lat/lon[/radius_m] or polygon).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null the updated place, or null if it isn't the user's
     */
    public function update(int $userId, int $id, array $data): ?array
    {
        $cur = $this->get($userId, $id);
        if ($cur === null) {
            return null;
        }
        // Merge onto the current place; a new shape replaces the old one entirely.
        $merged = ['name' => $cur['name'], 'type' => $cur['type']];
        if (isset($data['polygon'])) {
            $merged['polygon'] = $data['polygon'];
        } elseif (isset($data['lat'], $data['lon']) || $cur['shape'] === 'circle') {
            $merged['lat']      = $data['lat'] ?? $cur['lat'];
            $merged['lon']      = $data['lon'] ?? $cur['lon'];
            $merged['radius_m'] = $data['radius_m'] ?? $cur['radius_m'] ?? 100;
        } else {
            $merged['polygon'] = $cur['polygon'];
        }
        foreach (['name', 'type'] as $k) {
            if (isset($data[$k])) {
                $merged[$k] = $data[$k];
            }
        }
        $v = $this->validate($merged);
        $this->assertNameFree($userId, $v['name'], $id);

        try {
            $this->db->prepare(
                'UPDATE places SET name = :n, type = :t, shape = :s, lat = :lat, lon = :lon, radius_m = :r,
                 polygon = :poly, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :u'
            )->execute([
                ':n' => $v['name'], ':t' => $v['type'], ':s' => $v['shape'], ':lat' => $v['lat'], ':lon' => $v['lon'],
                ':r' => $v['radius_m'], ':poly' => $v['polygon'], ':id' => $id, ':u' => $userId,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new InvalidArgumentException('You already have a place called "' . $v['name'] . '".');
            }
            throw $e;
        }

        return $this->get($userId, $id);
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM places WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Whether a coordinate lies inside a place (circle: haversine ≤ radius; polygon: ray casting). */
    public static function contains(array $place, float $lat, float $lon): bool
    {
        if ($place['shape'] === 'polygon') {
            $poly   = $place['polygon'];
            $inside = false;
            $n      = count($poly);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$yi, $xi] = $poly[$i];
                [$yj, $xj] = $poly[$j];
                if ((($yi > $lat) !== ($yj > $lat))
                    && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                    $inside = !$inside;
                }
            }

            return $inside;
        }

        return LocationPoints::haversineKm($place['lat'], $place['lon'], $lat, $lon) * 1000 <= (float) $place['radius_m'];
    }

    /**
     * Time in places for one day's points (ordered by time): a visit is a run of consecutive
     * accurate points inside the place, from the first to the last of them. A gap between two
     * inside points counts as inside (iOS pauses updates when you're still). Runs shorter than
     * MIN_VISIT_MIN are pass-bys, unless they touch the start or end of the day's data (then you
     * were there before/after tracking saw it). On today, a run ending at the latest point is ongoing.
     *
     * @param list<array{ts:int, time:string, lat:float, lon:float, acc:?int}> $pts
     * @param list<array<string, mixed>> $places
     * @return list<array{place:string, type:string, minutes:int, visits:list<array<string, mixed>>, passes:int}>
     */
    public static function timeInPlaces(array $pts, array $places, bool $isToday = false): array
    {
        $good = array_values(array_filter(
            $pts,
            static fn (array $p): bool => $p['acc'] === null || $p['acc'] <= LocationPoints::GOOD_ACC_M
        ));
        $out = [];
        foreach ($places as $pl) {
            $visits = [];
            $passes = 0;
            $run    = null;
            $lastIdx = count($good) - 1;
            $flush  = static function () use (&$run, &$visits, &$passes): void {
                if ($run === null) {
                    return;
                }
                $mins = (int) round(($run['last']['ts'] - $run['first']['ts']) / 60);
                // A run touching the start or end of the day's data is a real visit however short
                // (you were there before the first point / after the last), never a pass-by.
                if ($mins >= self::MIN_VISIT_MIN || $run['ongoing'] || $run['edge']) {
                    $visits[] = ['from' => $run['first']['time'], 'to' => $run['last']['time'], 'minutes' => $mins, 'ongoing' => $run['ongoing']];
                } else {
                    $passes++;
                }
                $run = null;
            };
            foreach ($good as $i => $p) {
                if (self::contains($pl, $p['lat'], $p['lon'])) {
                    $run ??= ['first' => $p, 'last' => $p, 'ongoing' => false, 'edge' => $i === 0];
                    $run['last']    = $p;
                    $run['ongoing'] = $isToday && $i === $lastIdx;
                    $run['edge']    = $run['edge'] || $i === $lastIdx;
                } else {
                    $flush();
                }
            }
            $flush();
            if ($visits !== [] || $passes > 0) {
                $out[] = [
                    'place'   => $pl['name'],
                    'type'    => $pl['type'],
                    'minutes' => array_sum(array_column($visits, 'minutes')),
                    'visits'  => $visits,
                    'passes'  => $passes,
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        return $out;
    }

    /**
     * The places card (map editor in the app). $focus highlights one place; $here is the
     * user's latest position [lat, lon] for centring a new place. Coordinates stay out of the
     * stored chat history (_persist_strip) — a reopened card re-fetches.
     *
     * @param list<array<string, mixed>> $places
     * @param array{0:float, 1:float}|null $here
     * @return array<string, mixed>
     */
    public static function card(array $places, ?int $focus = null, ?array $here = null): array
    {
        return [
            'kind'           => 'places',
            'title'          => 'Places',
            'count'          => count($places),
            'types'          => self::TYPES,
            'min_radius_m'   => self::MIN_RADIUS_M,
            'max_radius_m'   => self::MAX_RADIUS_M,
            'focus'          => $focus,
            'places'         => $places,
            'here'           => $here,
            '_persist_strip' => ['places', 'here'],
        ];
    }

    /** A place without coordinates — what the model gets. */
    public static function forModel(array $p): array
    {
        return array_filter([
            'id'       => $p['id'],
            'name'     => $p['name'],
            'type'     => $p['type'],
            'shape'    => $p['shape'],
            'radius_m' => $p['shape'] === 'circle' ? $p['radius_m'] : null,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $d
     * @return array{name:string, type:string, shape:string, lat:float, lon:float, radius_m:?int, polygon:?string}
     */
    private function validate(array $d): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('A place needs a name (up to 64 characters).');
        }
        $type = strtolower(trim((string) ($d['type'] ?? 'other')));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Type must be one of: ' . implode(', ', self::TYPES) . '.');
        }

        if (isset($d['polygon']) && $d['polygon'] !== null) {
            $poly = is_string($d['polygon']) ? json_decode($d['polygon'], true) : $d['polygon'];
            if (!is_array($poly) || count($poly) < 3 || count($poly) > self::MAX_VERTICES) {
                throw new InvalidArgumentException('A polygon needs 3–' . self::MAX_VERTICES . ' corners.');
            }
            $clean = [];
            foreach ($poly as $v) {
                if (!is_array($v) || count($v) < 2 || !self::validCoord($v[0], $v[1])) {
                    throw new InvalidArgumentException('A polygon corner is not a valid coordinate.');
                }
                $clean[] = [round((float) $v[0], 6), round((float) $v[1], 6)];
            }
            $lat = array_sum(array_column($clean, 0)) / count($clean);
            $lon = array_sum(array_column($clean, 1)) / count($clean);

            return ['name' => $name, 'type' => $type, 'shape' => 'polygon', 'lat' => round($lat, 6), 'lon' => round($lon, 6),
                'radius_m' => null, 'polygon' => (string) json_encode($clean)];
        }

        if (!self::validCoord($d['lat'] ?? null, $d['lon'] ?? null)) {
            throw new InvalidArgumentException('A place needs a valid position.');
        }
        $r = (int) round((float) ($d['radius_m'] ?? 100));
        if ($r < self::MIN_RADIUS_M || $r > self::MAX_RADIUS_M) {
            throw new InvalidArgumentException('Radius must be ' . self::MIN_RADIUS_M . '–' . self::MAX_RADIUS_M . ' m.');
        }

        return ['name' => $name, 'type' => $type, 'shape' => 'circle', 'lat' => round((float) $d['lat'], 6),
            'lon' => round((float) $d['lon'], 6), 'radius_m' => $r, 'polygon' => null];
    }

    /** Names are unique per user regardless of case ("Gym" = "gym"), independent of DB collation. */
    private function assertNameFree(int $userId, string $name, ?int $exceptId): void
    {
        $same = $this->findByName($userId, $name);
        if ($same !== null && $same['id'] !== $exceptId) {
            throw new InvalidArgumentException('You already have a place called "' . $same['name'] . '".');
        }
    }

    private static function validCoord(mixed $lat, mixed $lon): bool
    {
        return is_numeric($lat) && is_numeric($lon) && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lon >= -180 && (float) $lon <= 180 && !((float) $lat == 0.0 && (float) $lon == 0.0);
    }

    /** @return array<string, mixed> */
    private function row(array $r): array
    {
        return [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'type'     => (string) $r['type'],
            'shape'    => (string) $r['shape'],
            'lat'      => (float) $r['lat'],
            'lon'      => (float) $r['lon'],
            'radius_m' => $r['radius_m'] !== null ? (int) $r['radius_m'] : null,
            'polygon'  => $r['polygon'] !== null ? (json_decode((string) $r['polygon'], true) ?: []) : null,
        ];
    }
}
