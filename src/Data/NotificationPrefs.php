<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Notify\NotificationTypes;
use PDO;

/**
 * Per-user on/off preference for each notification type. A missing row means
 * "use the type's default", so new types work without backfilling anything.
 */
final class NotificationPrefs
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Whether a user wants a given type (their setting, else the type default). */
    public function isEnabled(int $userId, string $type): bool
    {
        $stmt = $this->db->prepare(
            'SELECT enabled FROM notification_prefs WHERE user_id = :u AND type_key = :t LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':t' => $type]);
        $val = $stmt->fetchColumn();

        return $val === false ? NotificationTypes::defaultEnabled($type) : (bool) $val;
    }

    public function set(int $userId, string $type, bool $enabled): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notification_prefs (user_id, type_key, enabled) VALUES (:u, :t, :e)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        );
        $stmt->execute([':u' => $userId, ':t' => $type, ':e' => $enabled ? 1 : 0]);
    }

    /**
     * The full catalogue for a user with their effective enabled state — what the
     * settings UI renders.
     *
     * @return array<int, array{key:string, label:string, description:string, enabled:bool}>
     */
    public function forUser(int $userId): array
    {
        $set = [];
        $stmt = $this->db->prepare('SELECT type_key, enabled FROM notification_prefs WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $set[(string) $r['type_key']] = (bool) $r['enabled'];
        }

        $out = [];
        foreach (NotificationTypes::all() as $t) {
            $out[] = [
                'key'         => $t['key'],
                'label'       => $t['label'],
                'description' => $t['description'],
                'enabled'     => $set[$t['key']] ?? $t['default'],
            ];
        }

        return $out;
    }
}
