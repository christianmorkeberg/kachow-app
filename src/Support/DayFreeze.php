<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure helpers for freezing a day of detected movement (stays, trips, no-data gaps) and for
 * stitching frozen days back together. No DB, no places: only coordinates and times.
 *
 * A stay that crosses midnight is detected on both neighbouring days, so stitching merges
 * items that overlap in time instead of trusting equal timestamps (the two detections can start
 * from different padding and disagree by a point or two).
 */
final class DayFreeze
{
    public const VERSION = 1;

    /**
     * What to store for [from, to): every stay/trip/gap of a StayDetector result that overlaps
     * it, unclipped (a night at home keeps its true arrival and departure). A trip carries the
     * coordinates of the stays at both ends, since stay indexes mean nothing outside one run.
     *
     * @param array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>} $det
     * @return array<string, mixed>
     */
    public static function pack(array $det, int $from, int $to): array
    {
        $stays = [];
        foreach ($det['stays'] as $s) {
            if ($s['depart'] > $from && $s['arrive'] < $to) {
                $stays[] = [
                    'arrive' => (int) $s['arrive'],
                    'depart' => (int) $s['depart'],
                    'lat'    => (float) $s['lat'],
                    'lon'    => (float) $s['lon'],
                ];
            }
        }
        $trips = [];
        foreach ($det['trips'] as $t) {
            if ($t['end'] > $from && $t['start'] < $to) {
                $a = $det['stays'][$t['from_stay']];
                $b = $det['stays'][$t['to_stay']];
                $trips[] = [
                    'start'   => (int) $t['start'],
                    'end'     => (int) $t['end'],
                    'km'      => (float) $t['km'],
                    'mode'    => (string) $t['mode'],
                    'max_kmh' => $t['max_kmh'] === null ? null : (int) $t['max_kmh'],
                    'a'       => [(float) $a['lat'], (float) $a['lon']],
                    'b'       => [(float) $b['lat'], (float) $b['lon']],
                ];
            }
        }
        $gaps = [];
        foreach ($det['gaps'] as $g) {
            if ($g['end'] > $from && $g['start'] < $to) {
                $gaps[] = ['start' => (int) $g['start'], 'end' => (int) $g['end']];
            }
        }

        return ['v' => self::VERSION, 'stays' => $stays, 'trips' => $trips, 'gaps' => $gaps];
    }

    /**
     * Several frozen days → one time-ordered set, overlapping duplicates merged.
     *
     * @param list<array<string, mixed>> $payloads
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    public static function merge(array $payloads): array
    {
        $stays = $trips = $gaps = [];
        foreach ($payloads as $p) {
            array_push($stays, ...($p['stays'] ?? []));
            array_push($trips, ...($p['trips'] ?? []));
            array_push($gaps, ...($p['gaps'] ?? []));
        }

        return [
            'stays' => self::overlaps($stays, 'arrive', 'depart'),
            'trips' => self::overlaps($trips, 'start', 'end'),
            'gaps'  => self::overlaps($gaps, 'start', 'end'),
        ];
    }

    /**
     * Drops frozen items reaching past $ts: the live detector owns everything from there on,
     * including an item that straddles it.
     *
     * @param array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>} $set
     * @return array{stays: list<array<string, mixed>>, trips: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    public static function cutAt(array $set, int $ts): array
    {
        return [
            'stays' => array_values(array_filter($set['stays'], static fn (array $s): bool => $s['depart'] <= $ts)),
            'trips' => array_values(array_filter($set['trips'], static fn (array $t): bool => $t['end'] <= $ts)),
            'gaps'  => array_values(array_filter($set['gaps'], static fn (array $g): bool => $g['end'] <= $ts)),
        ];
    }

    /**
     * Sort by start; an item starting before the previous one ends is the same thing seen from
     * the next day — widen the previous one instead of listing it twice.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function overlaps(array $items, string $start, string $end): array
    {
        usort($items, static fn (array $x, array $y): int => $x[$start] <=> $y[$start]);
        $out = [];
        foreach ($items as $it) {
            $last = count($out) - 1;
            if ($last >= 0 && $it[$start] < $out[$last][$end]) {
                $out[$last][$end] = max($out[$last][$end], $it[$end]);
                continue;
            }
            $out[] = $it;
        }

        return $out;
    }
}
