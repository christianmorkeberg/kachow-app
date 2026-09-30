<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\StayDetector;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The user's movement as STAYS and TRIPS (location tracking phase 3), computed on demand from
 * the raw points with StayDetector and matched to their places. Nothing derived is stored
 * yet, so tuning the thresholds (user settings location_*) re-computes past days too.
 * Persisting derived stays beyond the 60-day raw retention is a later step (see the spec).
 *
 * Everything returned for the MODEL is coordinate-free (place names, times, km); coordinates
 * only go into cards.
 */
final class Timeline
{
    /** Points this far before/after a range are included so stays crossing its edges are whole. */
    private const PAD_S = 12 * 3600;

    /** Longest range a single stays/trips query may cover. */
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private LocationPoints $points,
        private Places $places,
        private UserSettings $settings,
    ) {
    }

    /** The user's detection thresholds (settings, clamped to sane ranges). */
    public function params(int $userId): array
    {
        $g = fn (string $k, int $min, int $max, int $def): int =>
            max($min, min($max, (int) round((float) ($this->settings->get($userId, $k) ?? $def))));

        return [
            'radius_m'          => $g('location_stay_radius_m', 30, 500, StayDetector::DEFAULTS['radius_m']),
            'min_minutes'       => $g('location_stay_min_minutes', 3, 60, StayDetector::DEFAULTS['min_minutes']),
            'max_acc_m'         => $g('location_max_accuracy_m', 20, 500, StayDetector::DEFAULTS['max_acc_m']),
            'merge_gap_minutes' => $g('location_merge_gap_minutes', 0, 120, StayDetector::DEFAULTS['merge_gap_minutes']),
        ];
    }

    /**
     * Stays + trips overlapping [fromTs, toTs), enriched with place names and local times.
     *
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, places: list<array<string, mixed>>, params: array<string, int>}
     */
    public function analyse(int $userId, int $fromTs, int $toTs): array
    {
        $params = $this->params($userId);
        $pts    = $this->points->between($userId, $fromTs - self::PAD_S, $toTs + self::PAD_S);
        $now    = time();
        $det    = StayDetector::detect($pts, $params, $toTs + self::PAD_S >= $now ? $now : null);
        $places = $this->places->list($userId);

        $stays = [];
        foreach ($det['stays'] as $k => $s) {
            $place = null;
            foreach ($places as $pl) {
                if (Places::matchesStay($pl, $s['lat'], $s['lon'])) {
                    $place = $pl;
                    break;
                }
            }
            $det['stays'][$k]['place'] = $place;
            if ($s['depart'] <= $fromTs || $s['arrive'] >= $toTs) {
                continue;
            }
            $stays[] = [
                'idx'      => $k,
                'place'    => $place['name'] ?? null,
                'place_id' => $place['id'] ?? null,
                'type'     => $place['type'] ?? null,
                'arrive'   => $s['arrive'],
                'depart'   => $s['depart'],
                'ongoing'  => $s['ongoing'],
                'minutes'  => (int) round(($s['depart'] - $s['arrive']) / 60),
                // Minutes inside the requested range (a night at home crosses midnight).
                'minutes_in_range' => (int) round((min($s['depart'], $toTs) - max($s['arrive'], $fromTs)) / 60),
                'lat'      => $s['lat'],
                'lon'      => $s['lon'],
            ];
        }

        $trips = [];
        foreach ($det['trips'] as $t) {
            if ($t['end'] <= $fromTs || $t['start'] >= $toTs) {
                continue;
            }
            $a = $det['stays'][$t['from_stay']];
            $b = $det['stays'][$t['to_stay']];
            $trips[] = [
                'start'   => $t['start'],
                'end'     => $t['end'],
                'minutes' => (int) round(($t['end'] - $t['start']) / 60),
                'km'      => $t['km'],
                'mode'    => $t['mode'],
                'max_kmh' => $t['max_kmh'],
                'from'    => $a['place']['name'] ?? null,
                'to'      => $b['place']['name'] ?? null,
                'from_type' => $a['place']['type'] ?? null,
                'to_type'   => $b['place']['type'] ?? null,
                'from_place_id' => $a['place']['id'] ?? null,
                'to_place_id'   => $b['place']['id'] ?? null,
                'from_ll' => [$a['lat'], $a['lon']],
                'to_ll'   => [$b['lat'], $b['lon']],
            ];
        }

        return ['stays' => $stays, 'trips' => $trips, 'places' => $places, 'params' => $params];
    }

    /**
     * One local day: the phase-1 stats + map card, plus the timeline (stays and trips), time
     * per place, and the user's places drawn on the map.
     *
     * @return array{stats: array<string, mixed>, card: array<string, mixed>}
     */
    public function day(int $userId, string $localDate): array
    {
        $d = $this->points->day($userId, $localDate);
        [$from, $to] = LocationPoints::dayBounds($localDate);
        $a = $this->analyse($userId, $from, $to);

        $items = $this->items($a, $from, $to);
        $stats = $d['stats'];
        if ($stats['points'] > 0 || $items !== []) {
            $stats['timeline']  = array_map([self::class, 'forModel'], $items);
            $stats['in_places'] = self::inPlaces($a['stays'], $from, $to);
        }
        $card = $d['card'];
        $card['stats']    = $stats;
        $card['timeline'] = $items;
        $card['places']   = array_map(static fn (array $p): array => [
            'name' => $p['name'], 'type' => $p['type'], 'shape' => $p['shape'], 'lat' => $p['lat'],
            'lon' => $p['lon'], 'radius_m' => $p['radius_m'], 'polygon' => $p['polygon'],
        ], $a['places']);

        return ['stats' => $stats, 'card' => $card];
    }

    /**
     * Stays across a local date range, optionally at one place (by name): per-place totals
     * (visits, days, minutes) plus the stays themselves. For "how often was I at the gym in
     * October", "when did I leave the office on Thursday".
     *
     * @return array<string, mixed>
     */
    public function stays(int $userId, string $fromDate, string $toDate, ?string $placeName = null): array
    {
        [$from, $to] = $this->range($fromDate, $toDate);
        $a    = $this->analyse($userId, $from, $to);
        $want = $placeName !== null && trim($placeName) !== '' ? mb_strtolower(trim($placeName)) : null;

        $list = [];
        foreach ($a['stays'] as $s) {
            if ($want !== null && mb_strtolower((string) $s['place']) !== $want) {
                continue;
            }
            $list[] = self::forModel(['kind' => 'stay'] + $s, true);
        }
        $per = [];
        foreach ($a['stays'] as $s) {
            if ($want !== null && mb_strtolower((string) $s['place']) !== $want) {
                continue;
            }
            $key = $s['place'] ?? '(unnamed places)';
            $per[$key] ??= ['place' => $key, 'type' => $s['type'], 'visits' => 0, 'days' => [], 'minutes' => 0];
            $per[$key]['visits']++;
            $per[$key]['days'][self::local($s['arrive'], 'Y-m-d')] = true;
            $per[$key]['minutes'] += $s['minutes_in_range'];
        }
        $per = array_values(array_map(static function (array $r): array {
            $r['days'] = count($r['days']);
            return $r;
        }, $per));
        usort($per, static fn (array $x, array $y): int => $y['minutes'] <=> $x['minutes']);

        return [
            'range'   => self::local($from, 'j M') . ' – ' . self::local($to - 1, 'j M Y'),
            'summary' => $per,
            'stays'   => array_slice($list, 0, 150),
            'truncated' => count($list) > 150,
        ];
    }

    /**
     * Trips across a local date range, optionally one mode (walk / bike / vehicle).
     *
     * @return array<string, mixed>
     */
    public function trips(int $userId, string $fromDate, string $toDate, ?string $mode = null): array
    {
        [$from, $to] = $this->range($fromDate, $toDate);
        $a = $this->analyse($userId, $from, $to);
        $list = [];
        $km   = 0.0;
        foreach ($a['trips'] as $t) {
            if ($mode !== null && $mode !== '' && $t['mode'] !== $mode) {
                continue;
            }
            $km    += $t['km'];
            $list[] = self::forModel(['kind' => 'trip'] + $t, true);
        }

        return [
            'range'    => self::local($from, 'j M') . ' – ' . self::local($to - 1, 'j M Y'),
            'count'    => count($list),
            'total_km' => round($km, 1),
            'trips'    => array_slice($list, 0, 150),
            'truncated' => count($list) > 150,
        ];
    }

    /**
     * Unnamed places the user keeps coming back to (last 60 days): stays that match no saved
     * place, clustered by position. Offered on the places card as "name it?".
     *
     * @return list<array<string, mixed>> each with lat/lon (card only), visits, days, avg_minutes, typical
     */
    public function suggestions(int $userId, int $minDays = 2): array
    {
        $to   = time();
        $from = $to - LocationPoints::RETENTION_DAYS * 86400;
        $a    = $this->analyse($userId, $from, $to);
        $r    = max(150, $a['params']['radius_m'] * 1.5);

        $clusters = [];
        foreach ($a['stays'] as $s) {
            if ($s['place'] !== null) {
                continue;
            }
            $hit = null;
            foreach ($clusters as $ci => $c) {
                if (LocationPoints::haversineKm($c['lat'], $c['lon'], $s['lat'], $s['lon']) * 1000 <= $r) {
                    $hit = $ci;
                    break;
                }
            }
            if ($hit === null) {
                $clusters[] = ['lat' => $s['lat'], 'lon' => $s['lon'], 'stays' => []];
                $hit = count($clusters) - 1;
            }
            $c = &$clusters[$hit];
            $c['stays'][] = $s;
            $n = count($c['stays']);
            $c['lat'] += ($s['lat'] - $c['lat']) / $n;
            $c['lon'] += ($s['lon'] - $c['lon']) / $n;
            unset($c);
        }

        $out = [];
        foreach ($clusters as $c) {
            $days = [];
            $weekdays = 0;
            $arrivals = [];
            $mins = 0;
            foreach ($c['stays'] as $s) {
                $days[self::local($s['arrive'], 'Y-m-d')] = true;
                $weekdays += (int) self::local($s['arrive'], 'N') <= 5 ? 1 : 0;
                $arrivals[] = (int) self::local($s['arrive'], 'G') * 60 + (int) self::local($s['arrive'], 'i');
                $mins += $s['minutes'];
            }
            if (count($days) < $minDays) {
                continue;
            }
            sort($arrivals);
            $med = $arrivals[intdiv(count($arrivals), 2)];
            $n   = count($c['stays']);
            $out[] = [
                'lat'         => round($c['lat'], 6),
                'lon'         => round($c['lon'], 6),
                'visits'      => $n,
                'days'        => count($days),
                'avg_minutes' => (int) round($mins / $n),
                'typical'     => ($weekdays === $n ? 'weekdays' : ($weekdays === 0 ? 'weekends' : 'mixed days'))
                    . ', usually arriving ~' . sprintf('%02d:%02d', intdiv($med, 60), $med % 60),
            ];
        }
        usort($out, static fn (array $x, array $y): int => $y['days'] <=> $x['days'] ?: $y['visits'] <=> $x['visits']);

        return array_slice($out, 0, 8);
    }

    /**
     * Stays + trips interleaved in time order, clipped to the day, with display times and
     * coordinates (for the card).
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $a, int $from, int $to): array
    {
        $items = [];
        foreach ($a['stays'] as $s) {
            $items[] = ['kind' => 'stay'] + $s;
        }
        foreach ($a['trips'] as $t) {
            $items[] = ['kind' => 'trip'] + $t;
        }
        usort($items, static fn (array $x, array $y): int =>
            ($x['kind'] === 'stay' ? $x['arrive'] : $x['start']) <=> ($y['kind'] === 'stay' ? $y['arrive'] : $y['start']));

        return array_map(static function (array $it) use ($from, $to): array {
            if ($it['kind'] === 'stay') {
                $it['from']     = self::local(max($it['arrive'], $from), 'H:i');
                $it['to']       = $it['ongoing'] ? null : self::local(min($it['depart'], $to), 'H:i');
                $it['prev_day'] = $it['arrive'] < $from;
                $it['next_day'] = !$it['ongoing'] && $it['depart'] > $to;
                $it['minutes']  = $it['minutes_in_range'];
                unset($it['idx'], $it['minutes_in_range'], $it['place_id']);
            } else {
                $it['start_time'] = self::local($it['start'], 'H:i');
                $it['end_time']   = self::local($it['end'], 'H:i');
            }

            return $it;
        }, $items);
    }

    /** A timeline item / stay / trip for the model: no coordinates, no raw timestamps. */
    public static function forModel(array $it, bool $withDate = false): array
    {
        if ($it['kind'] === 'stay') {
            $out = [
                'kind'    => 'stay',
                'place'   => $it['place'] ?? null,
                'type'    => $it['type'] ?? null,
                'from'    => $it['from'] ?? self::local($it['arrive'], 'H:i'),
                'to'      => array_key_exists('to', $it) ? $it['to'] : ($it['ongoing'] ? null : self::local($it['depart'], 'H:i')),
                'minutes' => $it['minutes'],
                'ongoing' => $it['ongoing'],
            ];
            if ($withDate) {
                $out = ['day' => self::local($it['arrive'], 'D j M')] + $out;
            }
            if (!empty($it['prev_day'])) {
                $out['started_previous_day'] = true;
            }
            if (!empty($it['next_day'])) {
                $out['continues_next_day'] = true;
            }
            if ($out['place'] === null) {
                $out['place'] = 'unnamed place';
            }

            return $out;
        }

        $out = [
            'kind'    => 'trip',
            'from'    => $it['from'] ?? 'unnamed place',
            'to'      => $it['to'] ?? 'unnamed place',
            'start'   => $it['start_time'] ?? self::local($it['start'], 'H:i'),
            'end'     => $it['end_time'] ?? self::local($it['end'], 'H:i'),
            'minutes' => $it['minutes'],
            'km'      => $it['km'],
            'mode'    => $it['mode'],
        ];

        return $withDate ? ['day' => self::local($it['start'], 'D j M')] + $out : $out;
    }

    /**
     * Time per saved place within [from, to), from stays (same shape the day card used in
     * phase 2: place, type, minutes, visits[from, to, minutes, ongoing]).
     *
     * @param list<array<string, mixed>> $stays
     * @return list<array<string, mixed>>
     */
    private static function inPlaces(array $stays, int $from, int $to): array
    {
        $per = [];
        foreach ($stays as $s) {
            if ($s['place'] === null) {
                continue;
            }
            $per[$s['place']] ??= ['place' => $s['place'], 'type' => $s['type'], 'minutes' => 0, 'visits' => []];
            $per[$s['place']]['minutes'] += $s['minutes_in_range'];
            $per[$s['place']]['visits'][] = [
                'from'    => self::local(max($s['arrive'], $from), 'H:i'),
                'to'      => self::local(min($s['depart'], $to), 'H:i'),
                'minutes' => $s['minutes_in_range'],
                'ongoing' => $s['ongoing'],
            ];
        }
        $per = array_values($per);
        usort($per, static fn (array $x, array $y): int => $y['minutes'] <=> $x['minutes']);

        return $per;
    }

    /** @return array{0:int, 1:int} UTC [from, to) for an inclusive local date range (max 62 days). */
    private function range(string $fromDate, string $toDate): array
    {
        [$f]    = LocationPoints::dayBounds($fromDate);
        [, $t]  = LocationPoints::dayBounds($toDate);
        if ($t <= $f) {
            [$f] = LocationPoints::dayBounds($toDate);
            [, $t] = LocationPoints::dayBounds($fromDate);
        }
        $t = min($t, $f + self::MAX_RANGE_DAYS * 86400);

        return [$f, $t];
    }

    private static function local(int $ts, string $fmt): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(WorkEvents::LOCAL_TZ))->format($fmt);
    }
}
