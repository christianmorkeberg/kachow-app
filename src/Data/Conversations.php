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
     * populated for tool messages; $cardJson persists an assistant turn's rendered
     * card (JSON) so it can be re-shown when the conversation is reopened. Returns
     * the new message id.
     */
    public function addMessage(
        int $conversationId,
        string $role,
        string $content,
        ?string $toolName = null,
        ?string $cardJson = null
    ): int {
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Invalid message role: {$role}");
        }

        $stmt = $this->db->prepare(
            'INSERT INTO messages (conversation_id, role, content, tool_name, card)
             VALUES (:conversation_id, :role, :content, :tool_name, :card)'
        );
        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':role'            => $role,
            ':content'         => $content,
            ':tool_name'       => $toolName,
            ':card'            => $cardJson,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A user's conversations that have messages, newest activity first, each with a
     * title (may be null), a preview (first user message), message count, and the
     * last-activity timestamp — for the history list.
     *
     * @return array<int, array{id:int, title:?string, preview:string, count:int, last_at:string}>
     */
    public function listForUser(int $userId, int $limit = 60): array
    {
        $limit = max(1, min(200, $limit));
        $stmt  = $this->db->prepare(
            'SELECT c.id, c.title, COUNT(m.id) AS msg_count, MAX(m.created_at) AS last_at
             FROM conversations c
             JOIN messages m ON m.conversation_id = c.id
             WHERE c.user_id = :u
             GROUP BY c.id, c.title
             ORDER BY last_at DESC, MAX(m.id) DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId]);

        return $this->decorate($stmt->fetchAll());
    }

    /**
     * Conversations of the user containing a message that matches $query.
     *
     * @return array<int, array{id:int, title:?string, preview:string, count:int, last_at:string}>
     */
    public function searchForUser(int $userId, string $query, int $limit = 40): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $like  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';

        // 1) conversation ids (owned by the user) with a matching message.
        $stmt = $this->db->prepare(
            'SELECT DISTINCT m.conversation_id AS cid
             FROM messages m JOIN conversations c ON c.id = m.conversation_id
             WHERE c.user_id = :u AND m.content LIKE :q
             LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId, ':q' => $like]);
        $ids = array_map(static fn (array $r): int => (int) $r['cid'], $stmt->fetchAll());
        if ($ids === []) {
            return [];
        }

        // 2) aggregate those conversations.
        $in   = implode(',', $ids);
        $rows = $this->db->query(
            'SELECT c.id, c.title, COUNT(m.id) AS msg_count, MAX(m.created_at) AS last_at
             FROM conversations c JOIN messages m ON m.conversation_id = c.id
             WHERE c.id IN (' . $in . ')
             GROUP BY c.id, c.title
             ORDER BY last_at DESC, MAX(m.id) DESC'
        )->fetchAll();

        return $this->decorate($rows);
    }

    /** Deletes a conversation (and its messages via cascade), owner-scoped. */
    public function delete(int $userId, int $conversationId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM conversations WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $conversationId, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** Sets a conversation's title, owner-scoped. */
    public function setTitle(int $userId, int $conversationId, string $title): bool
    {
        $stmt = $this->db->prepare('UPDATE conversations SET title = :t WHERE id = :id AND user_id = :u');
        $stmt->execute([':t' => mb_substr(trim($title), 0, 120), ':id' => $conversationId, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /** The current title (or null), owner-scoped; null if not found/owned. */
    public function title(int $userId, int $conversationId): ?string
    {
        $stmt = $this->db->prepare('SELECT title FROM conversations WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $conversationId, ':u' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return $row['title'] !== null ? (string) $row['title'] : null;
    }

    /**
     * Adds a preview (first user message, truncated) to aggregate rows.
     *
     * @param array<int, array<string, mixed>> $rows each with id, title, msg_count, last_at
     * @return array<int, array{id:int, title:?string, preview:string, count:int, last_at:string}>
     */
    private function decorate(array $rows): array
    {
        $ids      = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $previews = $this->previewsFor($ids);

        $out = [];
        foreach ($rows as $r) {
            $id      = (int) $r['id'];
            $preview = trim((string) ($previews[$id] ?? ''));
            if (mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '…';
            }
            $out[] = [
                'id'      => $id,
                'title'   => $r['title'] !== null ? (string) $r['title'] : null,
                'preview' => $preview,
                'count'   => (int) $r['msg_count'],
                'last_at' => (string) ($r['last_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * First user message per conversation (two single-table queries, so it's safe
     * even against MySQL TEMPORARY tables which can't be referenced twice).
     *
     * @param array<int, int> $conversationIds
     * @return array<int, string> conversationId => first user message
     */
    private function previewsFor(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $conversationIds));

        $firstIds = [];
        foreach ($this->db->query(
            "SELECT conversation_id, MIN(id) AS mid FROM messages
             WHERE role = 'user' AND conversation_id IN ({$in}) GROUP BY conversation_id"
        )->fetchAll() as $r) {
            $firstIds[(int) $r['mid']] = (int) $r['conversation_id'];
        }
        if ($firstIds === []) {
            return [];
        }

        $midIn = implode(',', array_map('intval', array_keys($firstIds)));
        $out   = [];
        foreach ($this->db->query("SELECT id, content FROM messages WHERE id IN ({$midIn})")->fetchAll() as $r) {
            $out[$firstIds[(int) $r['id']]] = (string) $r['content'];
        }

        return $out;
    }

    /**
     * Returns the conversation's messages in chronological order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $conversationId, ?int $limit = null): array
    {
        $sql = 'SELECT id, role, content, tool_name, card, created_at
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
