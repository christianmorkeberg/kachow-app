<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * A per-user backlog of ideas for developing the app further, captured from chat
 * ("for later: …"). Deliberately simple — jot, list, remove.
 */
final class DevIdeas
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public function add(int $userId, string $idea): int
    {
        $stmt = $this->db->prepare('INSERT INTO dev_ideas (user_id, idea) VALUES (:u, :i)');
        $stmt->execute([':u' => $userId, ':i' => mb_substr(trim($idea), 0, 1000)]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<int, array{id:int, idea:string, created_at:string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, idea, created_at FROM dev_ideas WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'         => (int) $r['id'],
                'idea'       => (string) $r['idea'],
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM dev_ideas WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
