<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The saved name -> id list for a user's home devices (Hue lights, rooms, zones, scenes). Kept so
 * the assistant can act by name in one call. Plain SQL, portable across MySQL and SQLite.
 */
final class HomeDevices
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Replaces everything saved for one kind (e.g. all 'light' rows) with a fresh list — so a light
     * that was deleted or renamed in the Hue app disappears here too.
     *
     * @param list<array{name:string, id:string, group:?string}> $entries
     */
    public function replaceKind(int $userId, string $device, string $kind, array $entries): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM home_devices WHERE user_id = :u AND device = :d AND kind = :k')
                ->execute([':u' => $userId, ':d' => $device, ':k' => $kind]);
            $ins = $this->db->prepare(
                'INSERT INTO home_devices (user_id, device, kind, rid, name, grouped_id) VALUES (:u, :d, :k, :r, :n, :g)'
            );
            $seen = [];
            foreach ($entries as $e) {
                if (isset($seen[$e['id']])) {
                    continue;
                }
                $seen[$e['id']] = true;
                $ins->execute([
                    ':u' => $userId, ':d' => $device, ':k' => $kind, ':r' => $e['id'],
                    ':n' => mb_substr($e['name'], 0, 64), ':g' => $e['group'] ?? null,
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return list<array{type:string, name:string, id:string, group:?string}> in HueEntries shape
     */
    public function entries(int $userId, string $device): array
    {
        $stmt = $this->db->prepare(
            'SELECT kind, name, rid, grouped_id FROM home_devices WHERE user_id = :u AND device = :d ORDER BY kind, name'
        );
        $stmt->execute([':u' => $userId, ':d' => $device]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'type'  => (string) $r['kind'],
                'name'  => (string) $r['name'],
                'id'    => (string) $r['rid'],
                'group' => $r['grouped_id'] !== null ? (string) $r['grouped_id'] : null,
            ];
        }

        return $out;
    }
}
