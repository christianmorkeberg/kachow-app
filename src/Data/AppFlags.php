<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Tiny global key/value flag store (`app_flags`) for app-wide toggles that should be
 * flippable at runtime without a redeploy — e.g. whether to capture the model's
 * "thought" summaries into diagnostics (expensive; wanted mainly while bootstrapping
 * the feedback flow). Distinct from UserSettings, which is per-user.
 */
final class AppFlags
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Whether a flag is on. Returns $default when the flag has never been set. */
    public function isOn(string $key, bool $default = false): bool
    {
        $stmt = $this->db->prepare('SELECT flag_value FROM app_flags WHERE flag_key = :k');
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return $default;
        }

        return in_array(strtolower((string) $v), ['1', 'on', 'true', 'yes'], true);
    }

    /** Sets a flag on/off (stored as 'on'/'off'). */
    public function set(string $key, bool $on): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_flags (flag_key, flag_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE flag_value = VALUES(flag_value)'
        );
        $stmt->execute([':k' => $key, ':v' => $on ? 'on' : 'off']);
    }
}
