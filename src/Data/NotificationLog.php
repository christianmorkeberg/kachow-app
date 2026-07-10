<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Dedup ledger for periodic notifications. `claim()` records that a (user, type,
 * period) has been sent and reports whether THIS call was the first to do so, so a
 * scheduled send fires exactly once even if the cron runs more than once in the
 * window.
 */
final class NotificationLog
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Atomically claims a (user, type, period) slot. Returns true if this call
     * claimed it (caller should send), false if it was already claimed (skip).
     */
    public function claim(int $userId, string $type, string $periodKey): bool
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO notification_log (user_id, type_key, period_key) VALUES (:u, :t, :p)'
        );
        $stmt->execute([':u' => $userId, ':t' => $type, ':p' => $periodKey]);

        return $stmt->rowCount() > 0;
    }
}
