<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * General per-user settings — a small, controlled key/value store for structured
 * preferences the app and cron read directly (unlike UserInstructions, which is
 * free text steering the model).
 *
 * Keys are a fixed, self-documenting set (DEFS): adding a new per-user knob is one
 * entry here plus wherever reads it. Every query is hard-scoped to the user id.
 */
final class UserSettings
{
    /**
     * Known settings: key => { default, label, description }. The default is the
     * value used when the user hasn't set one.
     *
     * @var array<string, array{default:string, label:string, description:string}>
     */
    public const DEFS = [
        'work_calendar' => [
            'default'     => WorkLog::WORK_CALENDAR,
            'label'       => 'Work calendar',
            'description' => 'Name of the Google calendar whose events drive work-log tracking and the afternoon "what did you get done?" nudge.',
        ],
        'cycle_show_fertile' => [
            'default'     => 'off',
            'label'       => 'Show fertile window',
            'description' => 'Whether the cycle card shows the estimated fertile window ("on"/"off"). Off = show only phase and next period.',
        ],
    ];

    /** Interprets a stored setting value as a boolean (on/yes/true/1, incl. Danish ja). */
    public static function isTruthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', 'yes', 'true', '1', 'ja'], true);
    }

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function exists(string $key): bool
    {
        return isset(self::DEFS[$key]);
    }

    public static function defaultFor(string $key): ?string
    {
        return isset(self::DEFS[$key]) ? (string) self::DEFS[$key]['default'] : null;
    }

    /** @return array<int, string> the valid setting keys */
    public static function keys(): array
    {
        return array_keys(self::DEFS);
    }

    /** Current value for a key (stored value, else the default). Null if the key is unknown. */
    public function get(int $userId, string $key): ?string
    {
        if (!self::exists($key)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT setting_value FROM user_settings WHERE user_id = :u AND setting_key = :k');
        $stmt->execute([':u' => $userId, ':k' => $key]);
        $v = $stmt->fetchColumn();

        return $v === false ? (string) self::DEFS[$key]['default'] : (string) $v;
    }

    /**
     * Sets a known key, owner-scoped. An empty value resets it to the default (deletes
     * the row). Returns false only if the key is unknown.
     */
    public function set(int $userId, string $key, string $value): bool
    {
        if (!self::exists($key)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return $this->remove($userId, $key);
        }
        $value = mb_substr($value, 0, 255);

        $stmt = $this->db->prepare(
            'INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (:u, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([':u' => $userId, ':k' => $key, ':v' => $value]);

        return true;
    }

    /** Resets a key to its default (removes any stored override), owner-scoped. */
    public function remove(int $userId, string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_settings WHERE user_id = :u AND setting_key = :k');
        $stmt->execute([':u' => $userId, ':k' => $key]);

        return true;
    }

    /**
     * All known settings with the user's current value + default + metadata.
     *
     * @return array<int, array{key:string, label:string, description:string, value:string, default:string, is_custom:bool}>
     */
    public function all(int $userId): array
    {
        $stored = [];
        $stmt = $this->db->prepare('SELECT setting_key, setting_value FROM user_settings WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $stored[(string) $r['setting_key']] = (string) $r['setting_value'];
        }

        $out = [];
        foreach (self::DEFS as $key => $meta) {
            $out[] = [
                'key'         => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'value'       => $stored[$key] ?? (string) $meta['default'],
                'default'     => (string) $meta['default'],
                'is_custom'   => isset($stored[$key]),
            ];
        }

        return $out;
    }
}
