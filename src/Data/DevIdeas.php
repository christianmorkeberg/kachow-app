<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The app's backlog of ideas for developing it further, captured from chat ("for later:
 * …"). ONE shared backlog: any user can add to it and user_id records who suggested the
 * idea, but it's the developer's (admin's) list — the admin sees and prunes every idea
 * (listAll/deleteAny), others see their own (report #19: a non-admin's idea landed in a
 * private list the developer never saw).
 *
 * Each idea has a status — new → in_progress (taken into the LLM backlog / being built) →
 * done — that only the admin changes; it drives the dev-ideas card.
 */
final class DevIdeas
{
    /** Status key => English label (the card translates on the client). */
    public const STATUSES = [
        'new'         => 'New',
        'in_progress' => 'In progress',
        'done'        => 'Done',
    ];

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
     * @return array<int, array{id:int, idea:string, status:string, created_at:string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, idea, status, created_at FROM dev_ideas WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'         => (int) $r['id'],
                'idea'       => (string) $r['idea'],
                'status'     => self::status((string) $r['status']),
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Every idea on the backlog, newest first, with who suggested it.
     *
     * @return array<int, array{id:int, idea:string, status:string, created_at:string, user_id:int, from:string}>
     */
    public function listAll(): array
    {
        $rows = $this->db->query(
            'SELECT d.id, d.idea, d.status, d.created_at, d.user_id, u.name
             FROM dev_ideas d LEFT JOIN users u ON u.id = d.user_id
             ORDER BY d.id DESC'
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $name  = trim((string) ($r['name'] ?? ''));
            $out[] = [
                'id'         => (int) $r['id'],
                'idea'       => (string) $r['idea'],
                'status'     => self::status((string) $r['status']),
                'created_at' => (string) $r['created_at'],
                'user_id'    => (int) $r['user_id'],
                'from'       => $name !== '' ? $name : 'user #' . (int) $r['user_id'],
            ];
        }

        return $out;
    }

    /**
     * Admin: moves one idea to a status. Returns false for an unknown id or status.
     */
    public function setStatus(int $id, string $status): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE dev_ideas SET status = :s, status_at = :at WHERE id = :id');
        $stmt->execute([':s' => $status, ':at' => gmdate('Y-m-d H:i:s'), ':id' => $id]);
        if ($stmt->rowCount() > 0) {
            return true;
        }
        // MySQL reports 0 changed rows when the status was already that value — still a success.
        $chk = $this->db->prepare('SELECT 1 FROM dev_ideas WHERE id = :id');
        $chk->execute([':id' => $id]);

        return $chk->fetchColumn() !== false;
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

    /**
     * The dev-ideas card (kind: dev_ideas). $isAdmin = the viewer may change statuses and sees
     * who suggested each idea. The client filters by status (default: everything not done).
     *
     * @param list<array<string, mixed>> $ideas from listAll() / listForUser()
     * @return array<string, mixed>
     */
    public static function card(array $ideas, bool $isAdmin, int $viewerId, ?string $filter = null): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        $items  = [];
        foreach ($ideas as $i) {
            $counts[$i['status']]++;
            $ts   = strtotime((string) $i['created_at']);
            $item = [
                'id'     => (int) $i['id'],
                'idea'   => (string) $i['idea'],
                'status' => (string) $i['status'],
                'noted'  => $ts !== false ? date('j M', $ts) : '',
            ];
            if ($isAdmin && isset($i['user_id'])) {
                $item['from'] = (int) $i['user_id'] === $viewerId ? null : (string) ($i['from'] ?? '');
            }
            $items[] = $item;
        }

        return [
            'kind'     => 'dev_ideas',
            'title'    => 'Dev ideas',
            'can_edit' => $isAdmin,
            'filter'   => in_array($filter, ['open', 'all', ...array_keys(self::STATUSES)], true) ? $filter : 'open',
            'counts'   => $counts,
            'statuses' => self::STATUSES,
            'items'    => $items,
        ];
    }

    /** A stored status, or 'new' for anything unexpected (e.g. before the migration ran). */
    private static function status(string $s): string
    {
        return isset(self::STATUSES[$s]) ? $s : 'new';
    }
}
