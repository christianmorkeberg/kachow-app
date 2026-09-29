<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Derives work SESSIONS (clock in/out) from the stays at work-type places for ONE local day
 * — location tracking phase 4, run in shadow alongside the Shortcut punches. Pure and static,
 * so the accuracy-critical rules are unit-testable with plain arrays (no DB).
 *
 * Rules (from the location spec, "Work clock rules"):
 * - **Dwell:** a work stay under `min_dwell_minutes` is a drive-by and never opens or extends
 *   a session.
 * - **Same-day bridging:** returning to the SAME workplace later the same day bridges the gap
 *   as worked time — but only if the gap is ≤ `max_bridge_hours` and no OTHER work place was
 *   visited in between (A→B→A is two sessions, not one). Because we process every work stay in
 *   time order, a different place between two same-place stays naturally breaks the bridge.
 * - **Leaving / midnight:** a session provisionally closes at the last point inside; the day is
 *   finalised at local midnight (callers pass [dayStart, dayEnd) and clip). Today's last session
 *   can be ongoing.
 *
 * Coordinate-free: input stays already carry a place name/id/type; output is times + place only.
 */
final class WorkClock
{
    public const DEFAULTS = [
        'min_dwell_minutes' => 10,
        'max_bridge_hours'  => 3,
    ];

    /**
     * Work sessions for one local day.
     *
     * @param array<int, array{place_id:?int, place:?string, arrive:int, depart:int, ongoing:bool}> $workStays
     *        stays already filtered to type 'work' (UTC epoch seconds), any order
     * @param int $dayStart local-day start (UTC epoch)
     * @param int $dayEnd   local-day end (UTC epoch, exclusive)
     * @param array{min_dwell_minutes:int, max_bridge_hours:int} $params
     * @return array<int, array{place_id:?int, place:?string, in:int, out:int, ongoing:bool, minutes:int}>
     */
    public static function sessions(array $workStays, int $dayStart, int $dayEnd, array $params): array
    {
        $minDwell  = max(0, $params['min_dwell_minutes']) * 60;
        $maxBridge = max(0, $params['max_bridge_hours']) * 3600;

        // Clip each stay to the day and drop drive-bys (< min dwell within the day).
        $clean = [];
        foreach ($workStays as $s) {
            $in  = max((int) $s['arrive'], $dayStart);
            $out = min((int) $s['depart'], $dayEnd);
            if ($out - $in < $minDwell) {
                continue;
            }
            $clean[] = [
                'place_id' => $s['place_id'] ?? null,
                'place'    => $s['place'] ?? null,
                'in'       => $in,
                'out'      => $out,
                // Ongoing only if the real stay is still open AND it wasn't cut by midnight.
                'ongoing'  => !empty($s['ongoing']) && (int) $s['depart'] <= $dayEnd,
            ];
        }
        usort($clean, static fn (array $a, array $b): int => $a['in'] <=> $b['in']);

        $sessions = [];
        foreach ($clean as $s) {
            $n = count($sessions);
            if ($n > 0) {
                $last = &$sessions[$n - 1];
                if ($last['place_id'] === $s['place_id']
                    && $last['place_id'] !== null
                    && ($s['in'] - $last['out']) <= $maxBridge) {
                    // Same workplace, gap within cap, nothing else between → one bridged session.
                    if ($s['out'] > $last['out']) {
                        $last['out'] = $s['out'];
                    }
                    $last['ongoing'] = $s['ongoing'];
                    unset($last);
                    continue;
                }
                unset($last);
            }
            $sessions[] = ['place_id' => $s['place_id'], 'place' => $s['place'], 'in' => $s['in'], 'out' => $s['out'], 'ongoing' => $s['ongoing']];
        }

        foreach ($sessions as $k => $s) {
            $sessions[$k]['minutes'] = (int) round(($s['out'] - $s['in']) / 60);
        }

        return $sessions;
    }
}
