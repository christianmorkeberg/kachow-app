<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * Data-access layer for the `conversations` and `messages` tables.
 *
 * (Not in the spec's original Data/ list, but the layering rule — only Data/
 * touches MySQL — means chat persistence needs its own data class. Assistant/
 * calls into here rather than running SQL.)
 */
final class Conversations
{
    private const ROLES = ['user', 'assistant', 'tool'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Starts a new conversation for a user and returns its id.
     */
    public function start(int $userId): int
    {
        $stmt = $this->db->prepare('INSERT INTO conversations (user_id) VALUES (:user_id)');
        $stmt->execute([':user_id' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the owning user id of a conversation, or null if it doesn't exist.
     * Used to authorize that a conversation belongs to the acting user.
     */
    public function ownerId(int $conversationId): ?int
    {
        $stmt = $this->db->prepare('SELECT user_id FROM conversations WHERE id = :id');
        $stmt->execute([':id' => $conversationId]);
        $owner = $stmt->fetchColumn();

        return $owner === false ? null : (int) $owner;
    }

    /**
     * Appends a message. $role is 'user', 'assistant', or 'tool'; $toolName is
     * populated for tool messages. Returns the new message id.
     */
    public function addMessage(int $conversationId, string $role, string $content, ?string $toolName = null): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Invalid message role: {$role}");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO messages (conversation_id, role, content, tool_name)
             VALUES (:conversation_id, :role, :content, :tool_name)'
        );
        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':role'            => $role,
            ':content'         => $content,
            ':tool_name'       => $toolName,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the conversation's messages in chronological order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $conversationId, ?int $limit = null): array
    {
        $sql = 'SELECT id, role, content, tool_name, created_at
                FROM messages
                WHERE conversation_id = :conversation_id
                ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':conversation_id' => $conversationId]);

        return $stmt->fetchAll();
    }
}
