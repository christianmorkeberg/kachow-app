<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `vinyls` — the user's record collection with taste
 * signals (heard flag, rating) and Discogs-sourced genre/style for matching.
 */
final class Vinyls
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Inserts a vinyl. $v keys: artist, title (required); optional genre, style,
     * year, discogs_id, cover_url, heard, rating, notes. Returns the new id.
     *
     * @param array<string, mixed> $v
     */
    public function add(int $userId, array $v): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO vinyls
                (user_id, artist, title, genre, style, year, discogs_id, cover_url, heard, rating, notes)
             VALUES
                (:user_id, :artist, :title, :genre, :style, :year, :discogs_id, :cover_url, :heard, :rating, :notes)'
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':artist'     => (string) ($v['artist'] ?? ''),
            ':title'      => (string) ($v['title'] ?? ''),
            ':genre'      => $v['genre']      ?? null,
            ':style'      => $v['style']      ?? null,
            ':year'       => $v['year']       ?? null,
            ':discogs_id' => $v['discogs_id'] ?? null,
            ':cover_url'  => $v['cover_url']  ?? null,
            ':heard'      => !empty($v['heard']) ? 1 : 0,
            ':rating'     => $v['rating']     ?? null,
            ':notes'      => $v['notes']      ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vinyls WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Lists the user's vinyls with optional filters. $genre matches within the
     * genre OR style text. $heard filters heard/unheard. $minRating filters by
     * rating. Ordered by rating (highest first), then most recently added.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $userId, ?string $genre = null, ?bool $heard = null, ?int $minRating = null): array
    {
        $sql    = 'SELECT * FROM vinyls WHERE user_id = :uid';
        $params = [':uid' => $userId];

        if ($genre !== null && $genre !== '') {
            $sql .= ' AND (genre LIKE :g OR style LIKE :g2)';
            $params[':g']  = '%' . $genre . '%';
            $params[':g2'] = '%' . $genre . '%';
        }
        if ($heard !== null) {
            $sql .= ' AND heard = :heard';
            $params[':heard'] = $heard ? 1 : 0;
        }
        if ($minRating !== null) {
            $sql .= ' AND rating >= :minr';
            $params[':minr'] = $minRating;
        }

        $sql .= ' ORDER BY rating IS NULL, rating DESC, added_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Updates only the given fields (allowed: artist, title, genre, style, year,
     * discogs_id, cover_url, heard, rating, notes). Ownership-checked.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $userId, int $id, array $fields): bool
    {
        $allowed = ['artist', 'title', 'genre', 'style', 'year', 'discogs_id', 'cover_url', 'heard', 'rating', 'notes'];

        $set    = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = :{$column}";
                $params[":{$column}"] = $column === 'heard' ? (!empty($fields[$column]) ? 1 : 0) : $fields[$column];
            }
        }
        if ($set === []) {
            return false;
        }

        if ($this->get($userId, $id) === null) {
            return false;
        }

        $params[':id']  = $id;
        $params[':uid'] = $userId;
        $stmt = $this->db->prepare(
            'UPDATE vinyls SET ' . implode(', ', $set) . ' WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute($params);

        return true;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM vinyls WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
