<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for shared shopping lists: `shared_lists` (named lists that
 * belong to a connection) and `shared_list_items` (their items).
 *
 * A list is owned jointly by the two people on a connection, not by a single
 * user — both read and write. Access control (verifying the acting user is a
 * member of the connection) is done by Tools\HouseholdAccess before any call
 * here; this class is scoped by connection_id / list_id and stays SQL-only.
 */
final class ShoppingLists
{
    /** Name of the auto-created default list (used when no list is named). */
    public const DEFAULT_NAME = 'Shopping';

    /** Checked items are auto-removed once they're older than this many hours. */
    public const KEEP_CHECKED_HOURS = 24;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * All lists for a connection, with item counts. Default list first.
     *
     * @return array<int, array{id:int, name:string, is_default:bool, total:int, remaining:int}>
     */
    public function lists(int $connectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.name, l.is_default,
                    COUNT(i.id) AS total,
                    COALESCE(SUM(CASE WHEN i.checked = 0 THEN 1 ELSE 0 END), 0) AS remaining
             FROM shared_lists l
             LEFT JOIN shared_list_items i ON i.list_id = l.id
             WHERE l.connection_id = :cid
             GROUP BY l.id, l.name, l.is_default
             ORDER BY l.is_default DESC, l.name ASC'
        );
        $stmt->execute([':cid' => $connectionId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'is_default' => (bool) $r['is_default'],
                'total'      => (int) $r['total'],
                'remaining'  => (int) $r['remaining'],
            ];
        }

        return $out;
    }

    /**
     * Finds a list by name within a connection, or null. Matches leniently so
     * near-identical names resolve to the same list: first an exact
     * case-insensitive match, then a "loose" match that ignores case, spaces,
     * hyphens and punctuation (so "to-do", "to do" and "todo" are the same list).
     */
    public function findByName(int $connectionId, string $name): ?array
    {
        $name = trim($name);

        $stmt = $this->db->prepare(
            'SELECT id, name, is_default FROM shared_lists
             WHERE connection_id = :cid AND LOWER(name) = LOWER(:name) LIMIT 1'
        );
        $stmt->execute([':cid' => $connectionId, ':name' => $name]);
        $r = $stmt->fetch();
        if ($r !== false) {
            return $r;
        }

        // Loose fallback: compare normalised names across the connection's lists.
        $target = $this->normalizeName($name);
        if ($target === '') {
            return null;
        }
        $all = $this->db->prepare('SELECT id, name, is_default FROM shared_lists WHERE connection_id = :cid');
        $all->execute([':cid' => $connectionId]);
        foreach ($all->fetchAll() as $row) {
            if ($this->normalizeName((string) $row['name']) === $target) {
                return $row;
            }
        }

        return null;
    }

    /** Lowercase and strip everything but letters/digits (spaces, hyphens, punctuation). */
    private function normalizeName(string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($s), 'UTF-8'));
    }

    public function create(int $connectionId, string $name, int $createdBy, bool $isDefault = false): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO shared_lists (connection_id, name, is_default, created_by)
             VALUES (:cid, :name, :def, :by)'
        );
        $stmt->execute([
            ':cid'  => $connectionId,
            ':name' => trim($name),
            ':def'  => $isDefault ? 1 : 0,
            ':by'   => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Returns the connection's default list id, creating it on first use. */
    public function ensureDefault(int $connectionId, int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM shared_lists WHERE connection_id = :cid AND is_default = 1 LIMIT 1'
        );
        $stmt->execute([':cid' => $connectionId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : $this->create($connectionId, self::DEFAULT_NAME, $userId, true);
    }

    /**
     * Resolves a list for a call: no name → the default (created if needed);
     * a name → the matching list, created if $createIfMissing (for adds).
     *
     * @return array{id:int, name:string, created?:bool}|array{error:string}
     */
    public function resolve(int $connectionId, ?string $name, int $userId, bool $createIfMissing): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['id' => $this->ensureDefault($connectionId, $userId), 'name' => self::DEFAULT_NAME];
        }

        $row = $this->findByName($connectionId, $name);
        if ($row !== null) {
            return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        if ($createIfMissing) {
            return ['id' => $this->create($connectionId, $name, $userId), 'name' => $name, 'created' => true];
        }

        return ['error' => "There's no shopping list called \"{$name}\"."];
    }

    /** Deletes a list (and its items via FK cascade), scoped to the connection. */
    public function delete(int $connectionId, int $listId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM shared_lists WHERE id = :id AND connection_id = :cid');
        $stmt->execute([':id' => $listId, ':cid' => $connectionId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Items in a list — unchecked first, then by insertion order — with who added each.
     *
     * @return array<int, array{id:int, item:string, checked:bool, added_by:string}>
     */
    public function items(int $listId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.id, i.item, i.checked, u.name AS added_name, u.email AS added_email
             FROM shared_list_items i
             LEFT JOIN users u ON u.id = i.added_by
             WHERE i.list_id = :lid
             ORDER BY i.checked ASC, i.id ASC'
        );
        $stmt->execute([':lid' => $listId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'item'     => (string) $r['item'],
                'checked'  => (bool) $r['checked'],
                'added_by' => (string) ($r['added_name'] ?: $r['added_email'] ?: 'someone'),
            ];
        }

        return $out;
    }

    /**
     * Renderable card for the chat widget (kind = shopping_list).
     *
     * @return array{kind:string, title:string, list_id:int, items:array<int,array{id:int,label:string,done:bool}>}
     */
    public function cardForList(int $connectionId, int $listId, string $name): array
    {
        $this->purgeStaleChecked($listId);

        $items = [];
        foreach ($this->items($listId) as $i) {
            $items[] = ['id' => (int) $i['id'], 'label' => (string) $i['item'], 'done' => (bool) $i['checked']];
        }

        return ['kind' => 'shopping_list', 'title' => $name, 'list_id' => $listId, 'items' => $items];
    }

    /**
     * Toggles an item's checked state by id, authorising via connection membership
     * (join through the list → connection → the acting user). Returns the list's
     * connection/name for rebuilding the card, or null if not found/authorised.
     *
     * @return array{list_id:int, connection_id:int, name:string}|null
     */
    public function toggleItem(int $userId, int $itemId, bool $checked): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.list_id, l.connection_id, l.name
             FROM shared_list_items i
             JOIN shared_lists l ON l.id = i.list_id
             JOIN user_connections c ON c.id = l.connection_id
             WHERE i.id = :id AND c.status = "accepted" AND (c.requester_id = :u1 OR c.addressee_id = :u2)
             LIMIT 1'
        );
        $stmt->execute([':id' => $itemId, ':u1' => $userId, ':u2' => $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $whenAt = $checked ? 'NOW()' : 'NULL';
        $this->db->prepare("UPDATE shared_list_items SET checked = :c, checked_by = :by, checked_at = {$whenAt} WHERE id = :id")
            ->execute([':c' => $checked ? 1 : 0, ':by' => $checked ? $userId : null, ':id' => $itemId]);

        return ['list_id' => (int) $row['list_id'], 'connection_id' => (int) $row['connection_id'], 'name' => (string) $row['name']];
    }

    public function addItem(int $listId, string $item, int $addedBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO shared_list_items (list_id, item, added_by) VALUES (:lid, :item, :by)'
        );
        $stmt->execute([':lid' => $listId, ':item' => trim($item), ':by' => $addedBy]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Marks matching items checked/unchecked (by exact case-insensitive item text).
     * Returns how many items matched.
     */
    public function setChecked(int $listId, string $item, bool $checked, int $userId): int
    {
        $ids = $this->matchItemIds($listId, $item);
        if ($ids === []) {
            return 0;
        }
        $in    = implode(',', array_fill(0, count($ids), '?'));
        $whenAt = $checked ? 'NOW()' : 'NULL';
        $stmt  = $this->db->prepare(
            "UPDATE shared_list_items SET checked = ?, checked_by = ?, checked_at = {$whenAt} WHERE id IN ({$in})"
        );
        $stmt->execute(array_merge([$checked ? 1 : 0, $checked ? $userId : null], $ids));

        return count($ids);
    }

    /** Removes matching items (by exact case-insensitive text). Returns count removed. */
    public function removeItem(int $listId, string $item): int
    {
        $ids = $this->matchItemIds($listId, $item);
        if ($ids === []) {
            return 0;
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("DELETE FROM shared_list_items WHERE id IN ({$in})");
        $stmt->execute($ids);

        return count($ids);
    }

    /**
     * Removes checked items that have been checked longer than KEEP_CHECKED_HOURS
     * (also legacy checked rows with no checked_at). Called lazily on list read, so
     * checked items linger ~a day then self-clean without a cron.
     */
    public function purgeStaleChecked(int $listId, ?int $hours = null): int
    {
        $h = max(1, (int) ($hours ?? self::KEEP_CHECKED_HOURS));
        $stmt = $this->db->prepare(
            "DELETE FROM shared_list_items
             WHERE list_id = :lid AND checked = 1
               AND (checked_at IS NULL OR checked_at < (NOW() - INTERVAL {$h} HOUR))"
        );
        $stmt->execute([':lid' => $listId]);

        return $stmt->rowCount();
    }

    /** Removes all checked-off items from a list. Returns count removed. */
    public function clearChecked(int $listId): int
    {
        $stmt = $this->db->prepare('DELETE FROM shared_list_items WHERE list_id = :lid AND checked = 1');
        $stmt->execute([':lid' => $listId]);

        return $stmt->rowCount();
    }

    /**
     * @return array<int, int> ids of items in the list matching the text
     */
    private function matchItemIds(int $listId, string $item): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM shared_list_items WHERE list_id = :lid AND LOWER(item) = LOWER(:item)'
        );
        $stmt->execute([':lid' => $listId, ':item' => trim($item)]);

        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
}
