<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `user_instructions` — standing preferences the user has
 * asked the assistant to remember across conversations (e.g. "log workouts in
 * kg", "keep replies short"). These are injected into the system prompt each
 * turn by Assistant/AssistantLoop.
 */
final class UserInstructions
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Stores an instruction for the user and returns its id.
     */
    public function add(int $userId, string $instruction): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_instructions (user_id, instruction) VALUES (:user_id, :instruction)'
        );
        $stmt->execute([
            ':user_id'     => $userId,
            ':instruction' => $instruction,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the user's instructions, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, instruction, created_at
             FROM user_instructions
             WHERE user_id = :user_id
             ORDER BY id ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Deletes one of the user's instructions by id. Returns true if a row was
     * removed (the user_id scope prevents deleting someone else's).
     */
    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_instructions WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
