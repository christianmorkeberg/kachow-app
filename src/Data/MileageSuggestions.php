<?php

declare(strict_types=1);

namespace App\Data;

use App\Maps\MapDistance;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Kørebog trip suggestions (location tracking phase 5). Turns detected car drives that touch a
 * business/commute/work place into ONE suggested driving day per (date, destination) — the unit the
 * mileage model logs — which the user confirms or dismisses on the mileage card. Nothing is
 * written until they confirm (→ Mileage::logTrip). Suggestions are derived on the fly from the
 * last 14 days of location; already-logged days (mileage_trips) and dismissed ones
 * (mileage_trip_dismissals) are filtered out.
 */
final class MileageSuggestions
{
    private const LOCAL_TZ = 'Europe/Copenhagen';
    private const DAYS      = 14;

    public function __construct(
        private Timeline $timeline,
        private Places $places,
        private Mileage $mileage,
        private MapDistance $maps,
    ) {
    }

    /**
     * Pure grouping: vehicle trips → one candidate per (date, business/commute place), summing the
     * day's leg km as a GPS sanity figure. No DB, unit-testable.
     *
     * @param list<array<string, mixed>> $trips each with date, mode, from/to, from_type/to_type, from_place_id/to_place_id, km
     * @return array<string, array{date:string, place_id:int, place:string, type:string, gps_km:float}>
     */
    public static function candidates(array $trips): array
    {
        $cand = [];
        foreach ($trips as $t) {
            if (($t['mode'] ?? null) !== 'vehicle') {
                continue;
            }
            $date = (string) ($t['date'] ?? '');
            $km   = (float) ($t['km'] ?? 0);
            $ends = [
                [$t['to_type'] ?? null, $t['to_place_id'] ?? null, $t['to'] ?? null],
                [$t['from_type'] ?? null, $t['from_place_id'] ?? null, $t['from'] ?? null],
            ];
            foreach ($ends as [$type, $pid, $name]) {
                // work places count too: DTU is a work place (phase-4 clock) AND a commute drive
                // for befordringsfradrag (phase 5). The tax treatment comes from the linked
                // mileage destination's type, not the place type.
                if ($pid === null || !in_array($type, ['business', 'commute', 'work'], true)) {
                    continue;
                }
                $key = $date . '|' . (int) $pid;
                $cand[$key] ??= ['date' => $date, 'place_id' => (int) $pid, 'place' => (string) $name, 'type' => (string) $type, 'gps_km' => 0.0];
                $cand[$key]['gps_km'] = round($cand[$key]['gps_km'] + $km, 1);
            }
        }

        return $cand;
    }

    /**
     * Pending suggestions for the last $days days, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $userId, int $days = self::DAYS): array
    {
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $today = new DateTimeImmutable('now', $tz);
        $fromDate = $today->modify('-' . max(1, $days - 1) . ' days')->format('Y-m-d');
        $toDate   = $today->format('Y-m-d');

        [$fromTs]   = LocationPoints::dayBounds($fromDate);
        [, $toTs]   = LocationPoints::dayBounds($toDate);

        $trips = [];
        foreach ($this->timeline->analyse($userId, $fromTs, $toTs)['trips'] as $t) {
            $t['date'] = (new DateTimeImmutable('@' . (int) $t['start']))->setTimezone($tz)->format('Y-m-d');
            $trips[] = $t;
        }
        $cand = self::candidates($trips);
        if ($cand === []) {
            return [];
        }

        $dismissed = $this->mileage->dismissals($userId, $fromDate, $toDate);
        $placeById = [];
        $home = null;
        foreach ($this->places->list($userId) as $p) {
            $placeById[$p['id']] = $p;
            if ($home === null && $p['type'] === 'home') {
                $home = $p;
            }
        }

        $out = [];
        foreach ($cand as $key => $c) {
            if (isset($dismissed[$key])) {
                continue;
            }
            $dest = $this->mileage->destinationForPlace($userId, $c['place_id']);
            if ($dest !== null && $this->mileage->tripExistsForDay($userId, $dest['id'], $c['date'])) {
                continue; // already logged that day for this destination
            }

            $km = $this->roundTripKm($dest, $home, $placeById[$c['place_id']] ?? null, $c['gps_km']);

            $homeName = $home['name'] ?? 'Home';
            $out[] = [
                'date'           => $c['date'],
                'label'          => $this->dayLabel($c['date']),
                'route'          => $homeName . ' → ' . $c['place'] . ' → ' . $homeName,
                'place_id'       => $c['place_id'],
                'place'          => $c['place'],
                'type'           => $dest['type'] ?? $c['type'],
                'destination_id' => $dest['id'] ?? null,
                'destination'    => $dest['name'] ?? null,
                'needs_link'     => $dest === null,
                'km'             => $km,
                'gps_km'         => $c['gps_km'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $out;
    }

    /** Attaches pending suggestions to a mileage card (best-effort — never breaks the card). */
    public function attach(array $card, int $userId): array
    {
        try {
            $card['suggestions'] = $this->pending($userId);
        } catch (Throwable $e) {
            error_log('mileage suggestions: ' . $e->getMessage());
            $card['suggestions'] = [];
        }

        return $card;
    }

    /**
     * Round-trip km: the destination's saved distance if set; else the ORS route home→place ×2;
     * else the GPS figure as a last resort. Null if nothing is available.
     */
    private function roundTripKm(?array $dest, ?array $home, ?array $place, float $gpsKm): ?float
    {
        if ($dest !== null && $dest['round_trip'] > 0) {
            return $dest['round_trip'];
        }
        if ($home !== null && $place !== null && $this->maps->isConfigured()) {
            try {
                return $this->maps->lookupByCoords($home['lat'], $home['lon'], $place['lat'], $place['lon'])['round_trip_km'];
            } catch (Throwable $e) {
                // fall through to GPS
            }
        }

        return $gpsKm > 0 ? $gpsKm : null;
    }

    private function dayLabel(string $date): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone(self::LOCAL_TZ)))->format('D j M');
    }
}
