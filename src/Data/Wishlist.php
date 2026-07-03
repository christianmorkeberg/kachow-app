<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for the `wishlist` table.
 *
 * Pure data access only — Tools/ (AddWishlistItem, GetWishlist) map the model's
 * arguments onto these methods.
 */
final class Wishlist
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Adds an item for the user and returns the new id.
     * Every field except $item is optional.
     */
    public function add(
        int $userId,
        string $item,
        ?string $category = null,
        ?string $url = null,
        ?float $price = null,
        ?int $priority = null,
        ?string $notes = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO wishlist (user_id, item, category, url, price, priority, notes)
             VALUES (:user_id, :item, :category, :url, :price, :priority, :notes)'
        );
        $stmt->execute([
            ':user_id'  => $userId,
            ':item'     => $item,
            ':category' => $category,
            ':url'      => $url,
            ':price'    => $price,
            ':priority' => $priority,
            ':notes'    => $notes,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the user's wishlist, optionally filtered by category.
     *
     * Ordered by priority (items with a priority first, ascending so 1 leads),
     * then newest first. Priority semantics (1 = highest) are a convention the
     * tool layer documents to the model; this class only orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $userId, ?string $category = null): array
    {
        $sql = 'SELECT id, item, category, url, price, priority, notes, added_at
                FROM wishlist
                WHERE user_id = :user_id';
        $params = [':user_id' => $userId];

        if ($category !== null) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }

        $sql .= ' ORDER BY priority IS NULL, priority ASC, added_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
