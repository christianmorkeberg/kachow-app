<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Readings of metering smart plugs (table power_readings, written every 5 minutes by bin/power-cron.php) and what can be worked out
 * from them: energy per hour (watts integrated over time) for a period, with gaps in the data left out instead of guessed.
 */
final class PowerReadings
{
    /** A gap between two readings longer than this is "no data", not a constant load (the poll runs every 5 minutes). */
    public const MAX_GAP_SECONDS = 900;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @param array{switch:?bool, watts:?float, volts:?float, amps:?float, kwh_total:?float} $r */
    public function add(int $userId, string $deviceId, string $name, bool $online, array $r, ?string $at = null): void
    {
        $this->db->prepare(
            'INSERT INTO power_readings (user_id, device_id, device_name, online, switch_on, watts, volts, amps, kwh_total, read_at)
             VALUES (:u, :d, :n, :o, :s, :w, :v, :a, :k, :t)'
        )->execute([
            ':u' => $userId, ':d' => $deviceId, ':n' => mb_substr($name, 0, 120), ':o' => $online ? 1 : 0,
            ':s' => $r['switch'] === null ? null : ($r['switch'] ? 1 : 0), ':w' => $r['watts'], ':v' => $r['volts'], ':a' => $r['amps'], ':k' => $r['kwh_total'],
            ':t' => $at ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** The plugs seen so far, with the time of the latest reading. @return list<array{device_id:string, name:string, last_at:string}> */
    public function plugs(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT device_id, MAX(read_at) AS last_at FROM power_readings WHERE user_id = :u GROUP BY device_id ORDER BY device_id'
        );
        $stmt->execute([':u' => $userId]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $n = $this->db->prepare('SELECT device_name FROM power_readings WHERE user_id = :u AND device_id = :d ORDER BY id DESC LIMIT 1');
            $n->execute([':u' => $userId, ':d' => $r['device_id']]);
            $out[] = ['device_id' => (string) $r['device_id'], 'name' => (string) $n->fetchColumn(), 'last_at' => (string) $r['last_at']];
        }

        return $out;
    }

    /**
     * Energy and load for one plug between two UTC instants.
     *
     * @return array{kwh:float, hours:array<int,float>, avg_watts:?float, peak_watts:?float, covered_seconds:int, readings:int}
     *         hours: UTC hour-start timestamp => kWh in that hour
     */
    public function energy(int $userId, string $deviceId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $utc  = new DateTimeZone('UTC');
        $stmt = $this->db->prepare(
            'SELECT watts, read_at FROM power_readings WHERE user_id = :u AND device_id = :d AND read_at >= :f AND read_at < :t AND watts IS NOT NULL ORDER BY read_at'
        );
        $stmt->execute([':u' => $userId, ':d' => $deviceId, ':f' => $from->setTimezone($utc)->format('Y-m-d H:i:s'), ':t' => $to->setTimezone($utc)->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll();

        $hours = [];
        $kwh   = 0.0;
        $cov   = 0;
        $peak  = null;
        $prev  = null;
        foreach ($rows as $r) {
            $t = (new DateTimeImmutable((string) $r['read_at'], $utc))->getTimestamp();
            $w = (float) $r['watts'];
            $peak = $peak === null ? $w : max($peak, $w);
            if ($prev !== null && $t - $prev['t'] > 0 && $t - $prev['t'] <= self::MAX_GAP_SECONDS) {
                // The load between two readings is taken as the average of the two (trapezoid), split at hour borders so each hour gets its own part.
                $avg = ($prev['w'] + $w) / 2.0;
                for ($s = $prev['t']; $s < $t;) {
                    $hourStart = $s - ($s % 3600);
                    $end       = min($t, $hourStart + 3600);
                    $e         = $avg * ($end - $s) / 3600.0 / 1000.0;
                    $hours[$hourStart] = ($hours[$hourStart] ?? 0.0) + $e;
                    $kwh += $e;
                    $cov += $end - $s;
                    $s = $end;
                }
            }
            $prev = ['t' => $t, 'w' => $w];
        }
        ksort($hours);

        return [
            'kwh' => round($kwh, 4), 'hours' => $hours, 'avg_watts' => $cov > 0 ? round($kwh * 1000.0 * 3600.0 / $cov, 1) : null,
            'peak_watts' => $peak, 'covered_seconds' => $cov, 'readings' => count($rows),
        ];
    }

    /** Drops readings older than $days days. */
    public function prune(int $days = 400): int
    {
        $stmt = $this->db->prepare('DELETE FROM power_readings WHERE read_at < :t');
        $stmt->execute([':t' => gmdate('Y-m-d H:i:s', time() - $days * 86400)]);

        return $stmt->rowCount();
    }
}
