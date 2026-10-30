<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Home\HomeOwner;
use PDO;
use Throwable;

/**
 * The latest known state of the home, per user and kind ('lights', 'thermostats', 'valves'), so reads need not ask the Pico — it handles ONE command
 * at a time and a valve read is ~7 s. bin/home-state-cron.php refreshes it every 5 minutes (the valves report every 5 minutes, measured by the thermostat
 * probe), writes by the assistant or the kiosk patch or drop the entry. Keyed by whoever owns the home agent (a connected person's shared home is theirs).
 * A missing table (migration 2026-10-30 not run) is never an error: get() answers null and put() does nothing — callers then read live, as before.
 */
final class HomeState
{
    /** A cached entry younger than this is served as it is: the 5-minute refresh plus slack for a slow cron run. */
    public const MAX_AGE_S = 420;

    private PDO $db;

    public function __construct(?PDO $db = null, private ?HomeOwner $owner = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return array{data: array<string, mixed>, at: int}|null at = unix time it was stored */
    public function get(int $userId, string $kind): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT payload, updated_at FROM home_state WHERE user_id = :u AND kind = :k');
            $stmt->execute([':u' => $this->key($userId), ':k' => $kind]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return null;
        }
        if ($row === false) {
            return null;
        }
        $data = json_decode((string) $row['payload'], true);
        $at   = strtotime((string) $row['updated_at'] . ' UTC');

        return is_array($data) && $at !== false ? ['data' => $data, 'at' => $at] : null;
    }

    /** The entry only if it is younger than $maxAgeS (default MAX_AGE_S). @return array{data: array<string, mixed>, at: int}|null */
    public function fresh(int $userId, string $kind, int $maxAgeS = self::MAX_AGE_S): ?array
    {
        $e = $this->get($userId, $kind);

        return $e !== null && time() - $e['at'] <= $maxAgeS ? $e : null;
    }

    /** @param array<string, mixed> $data */
    public function put(int $userId, string $kind, array $data, ?int $at = null): void
    {
        try {
            $u = $this->key($userId);
            $this->db->prepare('DELETE FROM home_state WHERE user_id = :u AND kind = :k')->execute([':u' => $u, ':k' => $kind]);
            $this->db->prepare('INSERT INTO home_state (user_id, kind, payload, updated_at) VALUES (:u, :k, :p, :t)')->execute([
                ':u' => $u, ':k' => $kind,
                ':p' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                ':t' => gmdate('Y-m-d H:i:s', $at ?? time()),
            ]);
        } catch (Throwable $e) {
            error_log('HomeState::put ' . $kind . ': ' . $e->getMessage());
        }
    }

    /**
     * Removes the entry and returns it — to exactly ONE caller: the delete decides, so two processes racing for the same entry (a poll that saw the valve
     * confirm and the gateway's call that says the same) cannot both get it. Null when it is gone or never was.
     *
     * @return array{data: array<string, mixed>, at: int}|null
     */
    public function take(int $userId, string $kind): ?array
    {
        $e = $this->get($userId, $kind);
        if ($e === null) {
            return null;
        }
        try {
            $stmt = $this->db->prepare('DELETE FROM home_state WHERE user_id = :u AND kind = :k');
            $stmt->execute([':u' => $this->key($userId), ':k' => $kind]);

            return $stmt->rowCount() > 0 ? $e : null;
        } catch (Throwable $x) {
            return null;
        }
    }

    public function forget(int $userId, string $kind): void
    {
        try {
            $this->db->prepare('DELETE FROM home_state WHERE user_id = :u AND kind = :k')->execute([':u' => $this->key($userId), ':k' => $kind]);
        } catch (Throwable $e) {
            // no table: nothing cached
        }
    }

    private function key(int $userId): int
    {
        try {
            return $this->owner?->resolve($userId)['owner_id'] ?? $userId;
        } catch (Throwable $e) {
            return $userId;
        }
    }
}
