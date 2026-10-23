<?php

declare(strict_types=1);

namespace App\Data;

use App\Support\DayFreeze;
use App\Support\StayDetector;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The user's movement as STAYS and TRIPS (location tracking phase 3), computed on demand from
 * the raw points with StayDetector and matched to their places, so tuning the thresholds (user
 * settings location_*) re-computes every day that still has raw points. Days older than the 60-day
 * raw retention come from frozen_location_days (bin/location-freeze-cron.php) instead.
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
        private ?FrozenDays $frozen = null,
    ) {
    }

    /**
     * Saves one finished local day's stays, trips and gaps (see bin/location-freeze-cron.php), so
     * the day survives its raw points being purged. Detected with the user's current settings.
     *
     * @return array{stays: int, trips: int, gaps: int}
     */
    public function freezeDay(int $userId, string $localDate): array
    {
        [$from, $to] = LocationPoints::dayBounds($localDate);
        $pts = $this->points->between($userId, $from - self::PAD_S, $to + self::PAD_S);
        $det = StayDetector::detect($pts, $this->params($userId), null);
        $pack = DayFreeze::pack($det, $from, $to);
        ($this->frozen ??= new FrozenDays())->save($userId, $localDate, $pack);

        return ['stays' => count($pack['stays']), 'trips' => count($pack['trips']), 'gaps' => count($pack['gaps'])];
    }

    /**
     * Stays/trips/gaps for [fromTs, toTs): computed live from the raw points while they exist, and
     * taken from the frozen days for the stretch whose raw points have been purged. The seam sits
     * at the end of the local day holding the oldest remaining point; anything straddling it is
     * the live detector's.
     *
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    private function detection(int $userId, int $fromTs, int $toTs, array $params): array
    {
        $earliest = $this->points->earliestTs($userId);
        $liveFrom = $earliest === null ? PHP_INT_MAX : LocationPoints::dayBounds(self::local($earliest, 'Y-m-d'))[1];

        $set = ['stays' => [], 'trips' => [], 'gaps' => []];
        if ($fromTs < $liveFrom) {
            $set = $this->frozenSet($userId, $fromTs, min($toTs, $liveFrom - 1));
            if ($liveFrom < $toTs) {
                $set = DayFreeze::cutAt($set, $liveFrom);
            }
        }

        $liveStart = max($fromTs, $liveFrom);
        if ($liveStart < $toTs) {
            $now = time();
            $pts = $this->points->between($userId, $liveStart - self::PAD_S, $toTs + self::PAD_S);
            $det = StayDetector::detect($pts, $params, $toTs + self::PAD_S >= $now ? $now : null);
            foreach ($det['stays'] as $s) {
                if ($s['depart'] > $liveStart) {
                    $set['stays'][] = $s;
                }
            }
            foreach ($det['trips'] as $t) {
                if ($t['end'] > $liveStart) {
                    $a = $det['stays'][$t['from_stay']];
                    $b = $det['stays'][$t['to_stay']];
                    $set['trips'][] = $t + ['a' => [$a['lat'], $a['lon']], 'b' => [$b['lat'], $b['lon']]];
                }
            }
            foreach ($det['gaps'] as $g) {
                if ($g['end'] > $liveStart) {
                    $set['gaps'][] = $g;
                }
            }
        }

        return $set;
    }

    /** The frozen part of a range, or nothing at all if the table isn't there yet. */
    private function frozenSet(int $userId, int $fromTs, int $toTs): array
    {
        $empty = ['stays' => [], 'trips' => [], 'gaps' => []];
        if ($toTs <= $fromTs) {
            return $empty;
        }
        try {
            $days = ($this->frozen ??= new FrozenDays())->between($userId, self::local($fromTs, 'Y-m-d'), self::local($toTs - 1, 'Y-m-d'));
        } catch (\Throwable $e) {
            error_log('Timeline frozen days: ' . $e->getMessage());

            return $empty;
        }
        $set = DayFreeze::merge(array_values($days));
        // Stays carry no ongoing flag or point count when frozen; the rest of analyse() expects them.
        $set['stays'] = array_map(static fn (array $s): array => $s + ['ongoing' => false], $set['stays']);
        $set['gaps']  = array_map(static fn (array $g): array => $g + [
            'minutes' => (int) round(($g['end'] - $g['start']) / 60), 'ongoing' => false,
        ], $set['gaps']);

        return $set;
    }

    /** The place a stay is at: when several contain it (a home inside a larger campus), the smallest wins — the most specific one. */
    private static function placeAt(array $places, float $lat, float $lon): ?array
    {
        $best = null;
        $bestArea = INF;
        foreach ($places as $pl) {
            if (Places::matchesStay($pl, $lat, $lon)) {
                $a = Places::areaM2($pl);
                if ($a < $bestArea) {
                    $best = $pl;
                    $bestArea = $a;
                }
            }
        }

        return $best;
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
            'max_gap_minutes'   => $g('location_max_gap_minutes', 15, 1440, StayDetector::DEFAULTS['max_gap_minutes']),
        ];
    }

    /**
     * Stays + trips overlapping [fromTs, toTs), enriched with place names and local times, plus
     * the GAPS where the phone reported nothing (so a hole in the data reads as a hole, not as a
     * long stay or an invented drive).
     *
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>, places: list<array<string, mixed>>, params: array<string, int>}
     */
    public function analyse(int $userId, int $fromTs, int $toTs): array
    {
        $params = $this->params($userId);
        $det    = $this->detection($userId, $fromTs, $toTs, $params);
        $places = $this->places->destinations($userId);   // regions are areas for routines, never somewhere you "stay"

        $stays = [];
        foreach ($det['stays'] as $k => $s) {
            $place = self::placeAt($places, $s['lat'], $s['lon']);
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
            $pa = self::placeAt($places, $t['a'][0], $t['a'][1]);
            $pb = self::placeAt($places, $t['b'][0], $t['b'][1]);
            $trips[] = [
                'start'   => $t['start'],
                'end'     => $t['end'],
                'minutes' => (int) round(($t['end'] - $t['start']) / 60),
                'km'      => $t['km'],
                'mode'    => $t['mode'],
                'max_kmh' => $t['max_kmh'],
                'from'    => $pa['name'] ?? null,
                'to'      => $pb['name'] ?? null,
                'from_type' => $pa['type'] ?? null,
                'to_type'   => $pb['type'] ?? null,
                'from_place_id' => $pa['id'] ?? null,
                'to_place_id'   => $pb['id'] ?? null,
                'from_ll' => $t['a'],
                'to_ll'   => $t['b'],
            ];
        }

        $gaps = [];
        foreach ($det['gaps'] as $g) {
            if ($g['end'] <= $fromTs || $g['start'] >= $toTs) {
                continue;
            }
            $gaps[] = $g + [
                'minutes_in_range' => (int) round((min($g['end'], $toTs) - max($g['start'], $fromTs)) / 60),
            ];
        }

        return ['stays' => $stays, 'trips' => $trips, 'gaps' => $gaps, 'places' => $places, 'params' => $params];
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
        foreach ($a['gaps'] as $g) {
            $items[] = ['kind' => 'gap'] + $g;
        }
        $at = static fn (array $it): int => $it['kind'] === 'stay' ? $it['arrive'] : $it['start'];
        usort($items, static fn (array $x, array $y): int => $at($x) <=> $at($y));

        return array_map(static function (array $it) use ($from, $to): array {
            if ($it['kind'] === 'stay') {
                $it['from']     = self::local(max($it['arrive'], $from), 'H:i');
                $it['to']       = $it['ongoing'] ? null : self::local(min($it['depart'], $to), 'H:i');
                $it['prev_day'] = $it['arrive'] < $from;
                $it['next_day'] = !$it['ongoing'] && $it['depart'] > $to;
                $it['minutes']  = $it['minutes_in_range'];
                unset($it['idx'], $it['minutes_in_range'], $it['place_id']);
            } else {
                $it['start_time'] = self::local(max($it['start'], $from), 'H:i');
                $it['end_time']   = self::local(min($it['end'], $to), 'H:i');
                if ($it['kind'] === 'gap') {
                    $it['prev_day'] = $it['start'] < $from;
                    $it['next_day'] = $it['end'] > $to;
                    $it['minutes']  = $it['minutes_in_range'];
                    unset($it['minutes_in_range']);
                }
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

        if ($it['kind'] === 'gap') {
            // Say it plainly: the assistant must never present a gap as time spent somewhere.
            $out = [
                'kind'    => 'gap',
                'note'    => $it['ongoing'] ?? false
                    ? 'NO DATA since here — the phone has stopped reporting positions'
                    : 'NO DATA for this stretch — the phone reported nothing, so where you were is unknown',
                'start'   => $it['start_time'] ?? self::local($it['start'], 'H:i'),
                'end'     => $it['end_time'] ?? self::local($it['end'], 'H:i'),
                'minutes' => $it['minutes'],
            ];
            if (!empty($it['prev_day'])) {
                $out['started_previous_day'] = true;
            }
            if (!empty($it['next_day'])) {
                $out['continues_next_day'] = true;
            }

            return $withDate ? ['day' => self::local($it['start'], 'D j M')] + $out : $out;
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
