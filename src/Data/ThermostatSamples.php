<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The thermostat probe's log (see bin/thermostat-probe-cron.php and Support\ThermostatProbe): one row per valve per read.
 */
final class ThermostatSamples
{
    public const KEEP_DAYS = 14;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * @param array<string, mixed> $s keys: thermostat_id, name, reported_ts, current_c, target_c, valve_pct, battery_pct, rssi, read_ms, error
     */
    public function record(int $userId, array $s, ?int $now = null): void
    {
        $utc = static fn (?int $ts): ?string => $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
        $this->db->prepare(
            'INSERT INTO thermostat_samples (user_id, thermostat_id, name, sampled_at, reported_at, current_c, target_c, valve_pct, battery_pct, rssi, read_ms, error)
             VALUES (:u, :t, :n, :at, :rep, :c, :tg, :v, :b, :r, :ms, :e)'
        )->execute([
            ':u' => $userId, ':t' => (int) $s['thermostat_id'], ':n' => mb_substr((string) $s['name'], 0, 60), ':at' => $utc($now ?? time()),
            ':rep' => $utc(isset($s['reported_ts']) ? (int) $s['reported_ts'] : null),
            ':c' => $s['current_c'] ?? null, ':tg' => $s['target_c'] ?? null, ':v' => $s['valve_pct'] ?? null,
            ':b' => $s['battery_pct'] ?? null, ':r' => $s['rssi'] ?? null, ':ms' => $s['read_ms'] ?? null,
            ':e' => isset($s['error']) ? mb_substr((string) $s['error'], 0, 200) : null,
        ]);
    }

    /**
     * Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $userId, int $hours, ?int $thermostatId = null, ?int $now = null): array
    {
        $sql    = 'SELECT thermostat_id, name, sampled_at, reported_at, current_c, target_c, valve_pct, battery_pct, rssi, read_ms, error
                   FROM thermostat_samples WHERE user_id = :u AND sampled_at >= :from';
        $params = [':u' => $userId, ':from' => gmdate('Y-m-d H:i:s', ($now ?? time()) - max(1, $hours) * 3600)];
        if ($thermostatId !== null) {
            $sql .= ' AND thermostat_id = :t';
            $params[':t'] = $thermostatId;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY sampled_at ASC, id ASC');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function purge(?int $now = null): int
    {
        $stmt = $this->db->prepare('DELETE FROM thermostat_samples WHERE sampled_at < :old');
        $stmt->execute([':old' => gmdate('Y-m-d H:i:s', ($now ?? time()) - self::KEEP_DAYS * 86400)]);

        return $stmt->rowCount();
    }
}
