<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\LocationPoints;

/**
 * Turns a time-ordered list of raw positions into STAYS (somewhere you stayed) and TRIPS
 * (movement between two stays). Location tracking phase 3 — pure and static, so it is
 * unit-testable and every threshold is a parameter the user can tune later.
 *
 * Rules:
 * - A stay is a run of accurate points that all lie within `radius_m` of the run's running
 *   centre, spanning at least `min_minutes`. A single stray fix inside a stay (one GPS
 *   jump) doesn't break it.
 * - A GAP between points of the same stay counts as still being there: iOS pauses updates
 *   when the phone is still, so silence means "didn't move", never "left".
 * - The stay ends when you left: silence after its last point means you were still there, so
 *   the departure is the next (outside) point's time minus the time to travel there (at
 *   ~36 km/h, never before the stay's last point). The final run when the window reaches
 *   "now" is ongoing — silence since the last point means you're still there.
 * - Two stays at the same spot separated by a short gap (≤ `merge_gap_minutes`, e.g. a
 *   GPS wobble) are merged.
 * - Drive-bys never become stays: they last a minute, far below `min_minutes`.
 */
final class StayDetector
{
    /** A lone stray fix is ignored only if you were "away" at most this long (minutes). */
    private const OUTLIER_MAX_AWAY_MIN = 120;

    /** Assumed speed (m/s, ~36 km/h) to back-date a departure from the first point after silence. */
    private const LEAVE_SPEED_MS = 10.0;

    public const DEFAULTS = [
        'radius_m'          => 100,
        'min_minutes'       => 10,
        'max_acc_m'         => 100,
        'merge_gap_minutes' => 20,
    ];

    /**
     * @param list<array{ts:int, lat:float, lon:float, acc:?int}> $pts   ordered by ts
     * @param array<string, int|float> $params  see DEFAULTS
     * @param int|null $now  when set, a final run touching the end of the data is open until $now
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>}
     */
    public static function detect(array $pts, array $params = [], ?int $now = null): array
    {
        $p    = $params + self::DEFAULTS;
        $good = array_values(array_filter(
            $pts,
            static fn (array $x): bool => $x['acc'] === null || $x['acc'] <= $p['max_acc_m']
        ));
        $n = count($good);

        $stays = [];
        $i     = 0;
        while ($i < $n) {
            [$lat, $lon, $cnt] = [$good[$i]['lat'], $good[$i]['lon'], 1];
            $last = $i;
            $j    = $i + 1;
            while ($j < $n) {
                if (self::m($lat, $lon, $good[$j]) <= $p['radius_m']) {
                    $cnt++;
                    $lat += ($good[$j]['lat'] - $lat) / $cnt;
                    $lon += ($good[$j]['lon'] - $lon) / $cnt;
                    $last = $j++;
                    continue;
                }
                // One stray fix (a GPS jump) with the next point back inside: skip it — unless the
                // time away is long enough to have been a real errand.
                if ($j + 1 < $n && self::m($lat, $lon, $good[$j + 1]) <= $p['radius_m']
                    && $good[$j + 1]['ts'] - $good[$last]['ts'] <= max($p['merge_gap_minutes'], self::OUTLIER_MAX_AWAY_MIN) * 60) {
                    $j++;
                    continue;
                }
                break;
            }

            $open  = $now !== null && $last === $n - 1;
            $endTs = $open ? max($now, $good[$last]['ts']) : $good[$last]['ts'];
            if (!$open && $last + 1 < $n) {
                // Silence after the last point here means the phone didn't move: you stayed until
                // shortly before the next (outside) point — minus the time to travel there.
                $next  = $good[$last + 1];
                $endTs = max($endTs, $next['ts'] - (int) round(self::m($lat, $lon, $next) / self::LEAVE_SPEED_MS));
            }
            $minutes = ($endTs - $good[$i]['ts']) / 60;
            if ($minutes >= $p['min_minutes']) {
                $stays[] = [
                    'first'   => $i,
                    'last'    => $last,
                    'arrive'  => $good[$i]['ts'],
                    'depart'  => $endTs,
                    'ongoing' => $open,
                    'lat'     => round($lat, 6),
                    'lon'     => round($lon, 6),
                    'points'  => $cnt,
                ];
                $i = $last + 1;
            } else {
                $i++;
            }
        }

        $stays = self::merge($stays, $good, $p);

        return ['stays' => $stays, 'trips' => self::trips($stays, $good)];
    }

    /**
     * @param list<array<string, mixed>> $stays
     * @param list<array<string, mixed>> $good
     * @return list<array<string, mixed>>
     */
    private static function merge(array $stays, array $good, array $p): array
    {
        $out = [];
        foreach ($stays as $s) {
            $prev = $out === [] ? null : $out[count($out) - 1];
            if ($prev !== null
                && $s['arrive'] - $prev['depart'] <= $p['merge_gap_minutes'] * 60
                && LocationPoints::haversineKm($prev['lat'], $prev['lon'], $s['lat'], $s['lon']) * 1000 <= $p['radius_m']) {
                $w = $prev['points'] + $s['points'];
                $out[count($out) - 1] = [
                    'first'   => $prev['first'],
                    'last'    => $s['last'],
                    'arrive'  => $prev['arrive'],
                    'depart'  => $s['depart'],
                    'ongoing' => $s['ongoing'],
                    'lat'     => round(($prev['lat'] * $prev['points'] + $s['lat'] * $s['points']) / $w, 6),
                    'lon'     => round(($prev['lon'] * $prev['points'] + $s['lon'] * $s['points']) / $w, 6),
                    'points'  => $w,
                ];
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * Movement between consecutive stays: distance along the accurate points, duration, and a
     * rough mode from speed (walk / bike / vehicle — car and train look the same by speed).
     *
     * @param list<array<string, mixed>> $stays
     * @param list<array<string, mixed>> $good
     * @return list<array<string, mixed>>
     */
    private static function trips(array $stays, array $good): array
    {
        $trips = [];
        for ($k = 1, $c = count($stays); $k < $c; $k++) {
            $a = $stays[$k - 1];
            $b = $stays[$k];
            $path = array_slice($good, $a['last'], $b['first'] - $a['last'] + 1);
            $km = 0.0;
            $speeds = [];
            for ($q = 1, $m = count($path); $q < $m; $q++) {
                $d  = LocationPoints::haversineKm($path[$q - 1]['lat'], $path[$q - 1]['lon'], $path[$q]['lat'], $path[$q]['lon']);
                // The first leg starts at the departure, not at the stay's last point — the phone
                // sat silent in between, which would otherwise make every drive look like a walk.
                $t0 = $q === 1 ? max($path[0]['ts'], $a['depart']) : $path[$q - 1]['ts'];
                $dt = max(1, $path[$q]['ts'] - $t0);
                $km += $d;
                $speeds[] = $d / ($dt / 3600);
            }
            sort($speeds);
            // A high-ish percentile of segment speeds: robust to waiting at lights / stations.
            $p80 = $speeds === [] ? 0.0 : $speeds[(int) floor(0.8 * (count($speeds) - 1))];
            $mode = $km < 0.05 ? 'unknown' : ($p80 < 8 ? 'walk' : ($p80 < 26 ? 'bike' : 'vehicle'));

            $trips[] = [
                'start'     => $a['depart'],
                'end'       => $b['arrive'],
                'km'        => round($km, 1),
                'mode'      => $mode,
                'max_kmh'   => $speeds === [] ? null : (int) round(end($speeds)),
                'points'    => max(0, count($path) - 2),
                'from_stay' => $k - 1,
                'to_stay'   => $k,
            ];
        }

        return $trips;
    }

    /** Metres from a centre to a point. */
    private static function m(float $lat, float $lon, array $pt): float
    {
        return LocationPoints::haversineKm($lat, $lon, $pt['lat'], $pt['lon']) * 1000;
    }
}
