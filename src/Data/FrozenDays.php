<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Storage for frozen location days (see Support\DayFreeze and bin/location-freeze-cron.php):
 * one JSON payload per user per local date. Written by the nightly cron, read by Timeline for
 * days whose raw points have aged out.
 */
final class FrozenDays
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return array<string, array<string, mixed>> payloads keyed by local date, for [fromDate, toDate] inclusive */
    public function between(int $userId, string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT day, data FROM frozen_location_days WHERE user_id = :u AND day >= :f AND day <= :t ORDER BY day'
        );
        $stmt->execute([':u' => $userId, ':f' => $fromDate, ':t' => $toDate]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $payload = json_decode((string) $r['data'], true);
            if (is_array($payload)) {
                $out[(string) $r['day']] = $payload;
            }
        }

        return $out;
    }

    public function has(int $userId, string $date): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM frozen_location_days WHERE user_id = :u AND day = :d');
        $stmt->execute([':u' => $userId, ':d' => $date]);

        return $stmt->fetchColumn() !== false;
    }

    /** Insert or replace one day. @param array<string, mixed> $payload */
    public function save(int $userId, string $date, array $payload): void
    {
        $json = (string) json_encode($payload);
        $upd  = $this->db->prepare('UPDATE frozen_location_days SET data = :j, frozen_at = CURRENT_TIMESTAMP WHERE user_id = :u AND day = :d');
        $upd->execute([':j' => $json, ':u' => $userId, ':d' => $date]);
        if ($upd->rowCount() > 0 || $this->has($userId, $date)) {
            return;
        }
        $this->db->prepare('INSERT INTO frozen_location_days (user_id, day, data) VALUES (:u, :d, :j)')
            ->execute([':u' => $userId, ':d' => $date, ':j' => $json]);
    }
}
