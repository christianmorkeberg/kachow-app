<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\Encryptor;
use PDO;

/**
 * Data-access layer for `memories` — lasting personal facts the assistant has
 * learned about the user (life, work, family, health, routines, goals, key
 * dates, preferences). Injected into the system prompt each turn by
 * Assistant/AssistantLoop so the assistant "knows" the user across conversations.
 *
 * Distinct from user_instructions (which is *how* to behave); this is *who the
 * user is*. Content is encrypted at rest with libsodium (see Support\Encryptor).
 */
final class Memories
{
    private PDO $db;
    private Encryptor $crypto;

    public function __construct(?PDO $db = null, ?Encryptor $crypto = null)
    {
        $this->db     = $db ?? Database::get();
        $this->crypto = $crypto ?? new Encryptor();
    }

    /**
     * Stores a fact for the user and returns its id. $content is encrypted here.
     */
    public function add(int $userId, string $content, string $category = 'general'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO memories (user_id, category, content) VALUES (:user_id, :category, :content)'
        );
        $stmt->execute([
            ':user_id'  => $userId,
            ':category' => $category !== '' ? $category : 'general',
            ':content'  => $this->crypto->encrypt($content),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the user's facts (oldest first) with content decrypted.
     *
     * @return array<int, array{id: int, category: string, content: string, created_at: mixed}>
     */
    public function all(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, category, content, created_at
             FROM memories
             WHERE user_id = :user_id
             ORDER BY id ASC'
        );
        $stmt->execute([':user_id' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'category'   => (string) $row['category'],
                'content'    => $this->crypto->decrypt((string) $row['content']),
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Updates a fact's content by id (user-scoped). Returns true if a row changed.
     */
    public function update(int $userId, int $id, string $content): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE memories SET content = :content WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':content' => $this->crypto->encrypt($content),
            ':id'      => $id,
            ':user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deletes a fact by id. The user_id scope prevents deleting someone else's.
     */
    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM memories WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
