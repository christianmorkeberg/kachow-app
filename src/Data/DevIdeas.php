<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The app's backlog of ideas for developing it further, captured from chat ("for later:
 * …"). Deliberately simple — jot, list, remove. ONE shared backlog: any user can add to it
 * and user_id records who suggested the idea, but it's the developer's (admin's) list — the
 * admin sees and prunes every idea (listAll/deleteAny), others see their own (report #19:
 * a non-admin's idea landed in a private list the developer never saw).
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

    /**
     * Every idea on the backlog, newest first, with who suggested it.
     *
     * @return array<int, array{id:int, idea:string, created_at:string, user_id:int, from:string}>
     */
    public function listAll(): array
    {
        $rows = $this->db->query(
            'SELECT d.id, d.idea, d.created_at, d.user_id, u.name
             FROM dev_ideas d LEFT JOIN users u ON u.id = d.user_id
             ORDER BY d.id DESC'
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $name  = trim((string) ($r['name'] ?? ''));
            $out[] = [
                'id'         => (int) $r['id'],
                'idea'       => (string) $r['idea'],
                'created_at' => (string) $r['created_at'],
                'user_id'    => (int) $r['user_id'],
                'from'       => $name !== '' ? $name : 'user #' . (int) $r['user_id'],
            ];
        }

        return $out;
    }

    /** Admin prune: removes an idea whoever suggested it. */
    public function deleteAny(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM dev_ideas WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM dev_ideas WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
