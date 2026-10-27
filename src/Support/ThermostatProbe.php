<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What the thermostat probe's log says about the valves: how often each one REALLY reports (the gateway's own "last heard"
 * time moving), how stale a reading is when we read it, how often the temperature changes, and the fastest fall seen — the
 * numbers that decide whether an open window can be detected from a valve.
 */
final class ThermostatProbe
{
    /** A fall within this many minutes counts as one drop. */
    public const DROP_WINDOW_MINUTES = 15;

    /**
     * @param list<array<string, mixed>> $rows oldest first, as ThermostatSamples::since returns them
     * @return list<array<string, mixed>> one entry per valve
     */
    public static function summary(array $rows): array
    {
        $byValve = [];
        foreach ($rows as $r) {
            $byValve[(int) $r['thermostat_id']][] = $r;
        }
        $out = [];
        foreach ($byValve as $id => $list) {
            $out[] = self::one($id, $list);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return array<string, mixed>
     */
    private static function one(int $id, array $list): array
    {
        $ts       = static fn (?string $utc): ?int => $utc === null || $utc === '' ? null : (strtotime($utc . ' UTC') ?: null);
        $errors   = 0;
        $ages     = [];      // minutes between the gateway hearing the valve and us reading it
        $reports  = [];      // distinct reports: [reported ts, current_c]
        $lastRep  = null;
        foreach ($list as $r) {
            if (!empty($r['error'])) {
                $errors++;
                continue;
            }
            $rep = $ts($r['reported_at'] ?? null);
            $at  = $ts((string) $r['sampled_at']);
            if ($rep !== null && $at !== null) {
                $ages[] = max(0, ($at - $rep) / 60);
            }
            if ($rep !== null && $rep !== $lastRep) {
                $reports[] = [$rep, isset($r['current_c']) ? (float) $r['current_c'] : null];
                $lastRep   = $rep;
            }
        }
        $gaps = [];
        $changes = 0;
        $maxStep = 0.0;
        for ($i = 1, $n = count($reports); $i < $n; $i++) {
            $gaps[] = ($reports[$i][0] - $reports[$i - 1][0]) / 60;
            if ($reports[$i][1] !== null && $reports[$i - 1][1] !== null && $reports[$i][1] !== $reports[$i - 1][1]) {
                $changes++;
                $maxStep = max($maxStep, abs($reports[$i][1] - $reports[$i - 1][1]));
            }
        }
        // the fastest fall: the largest (earlier − later) reading within the drop window
        $drop = ['c' => 0.0, 'minutes' => null];
        for ($i = 0, $n = count($reports); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n && ($reports[$j][0] - $reports[$i][0]) <= self::DROP_WINDOW_MINUTES * 60; $j++) {
                if ($reports[$i][1] !== null && $reports[$j][1] !== null && ($reports[$i][1] - $reports[$j][1]) > $drop['c']) {
                    $drop = ['c' => round($reports[$i][1] - $reports[$j][1], 1), 'minutes' => round(($reports[$j][0] - $reports[$i][0]) / 60, 1)];
                }
            }
        }
        $first = (string) $list[0]['sampled_at'];
        $last  = (string) $list[count($list) - 1]['sampled_at'];

        return [
            'thermostat_id' => $id,
            'name'          => (string) $list[0]['name'],
            'samples'       => count($list),
            'read_errors'   => $errors,
            'from'          => $first,
            'to'            => $last,
            'reports'       => count($reports),
            'minutes_between_reports' => $gaps === [] ? null : ['min' => round(min($gaps), 1), 'median' => round(self::median($gaps), 1), 'max' => round(max($gaps), 1)],
            'reading_age_minutes'     => $ages === [] ? null : ['median' => round(self::median($ages), 1), 'max' => round(max($ages), 1)],
            'temperature_changes'     => $changes,
            'biggest_step_c'          => $maxStep > 0 ? round($maxStep, 1) : null,
            'fastest_fall'            => $drop['minutes'] === null ? null : ['c' => $drop['c'], 'within_minutes' => $drop['minutes']],
        ];
    }

    /** @param non-empty-list<float|int> $v */
    private static function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 === 1 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
