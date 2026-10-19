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
 * done — that only the admin changes; it drives the dev-ideas card. An idea can also carry
 * `details` (longer notes: design, decisions, what is open) and a priority (normal | high —
 * high ideas sort first). This list is the app's live backlog; exportMarkdown() hands a
 * selection of it to a coding session.
 */
final class DevIdeas
{
    /** Status key => English label (the card translates on the client). */
    public const STATUSES = [
        'new'         => 'New',
        'in_progress' => 'In progress',
        'done'        => 'Done',
    ];

    /** Priority key => English label. High ideas are listed first. */
    public const PRIORITIES = [
        'normal' => 'Normal',
        'high'   => 'High',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public function add(int $userId, string $idea, ?string $details = null, string $priority = 'normal'): int
    {
        $stmt = $this->db->prepare('INSERT INTO dev_ideas (user_id, idea, details, priority) VALUES (:u, :i, :d, :p)');
        $stmt->execute([
            ':u' => $userId,
            ':i' => mb_substr(trim($idea), 0, 1000),
            ':d' => self::cleanDetails($details),
            ':p' => self::priority($priority),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The user's own ideas: high priority first, then newest first.
     *
     * @return list<array{id:int, idea:string, details:?string, priority:string, status:string, created_at:string, user_id:int}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, idea, details, priority, status, status_at, created_at, user_id FROM dev_ideas WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute([':u' => $userId]);

        return self::sorted(array_map([self::class, 'row'], $stmt->fetchAll()));
    }

    /**
     * Every idea on the backlog — high priority first, then newest first — with who suggested it.
     *
     * @return list<array{id:int, idea:string, details:?string, priority:string, status:string, created_at:string, user_id:int, from:string}>
     */
    public function listAll(): array
    {
        $rows = $this->db->query(
            'SELECT d.id, d.idea, d.details, d.priority, d.status, d.status_at, d.created_at, d.user_id, u.name
             FROM dev_ideas d LEFT JOIN users u ON u.id = d.user_id
             ORDER BY d.id DESC'
        )->fetchAll();

        return self::sorted(array_map([self::class, 'row'], $rows));
    }

    /**
     * Admin: the full records for some ids (any owner), in the order of the backlog list.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findMany(array $ids): array
    {
        $want = array_flip(array_map('intval', $ids));

        return array_values(array_filter($this->listAll(), static fn (array $i): bool => isset($want[$i['id']])));
    }

    /**
     * Admin: edits an idea — its text, its details (replaced, or appended as a new paragraph) and/or
     * its priority. Only the fields passed change. Returns false for an unknown id or priority.
     */
    public function update(int $id, ?string $idea = null, ?string $details = null, ?string $priority = null, bool $appendDetails = false): bool
    {
        $cur = $this->db->prepare('SELECT idea, details FROM dev_ideas WHERE id = :id');
        $cur->execute([':id' => $id]);
        $row = $cur->fetch();
        if ($row === false) {
            return false;
        }
        if ($priority !== null && !isset(self::PRIORITIES[$priority])) {
            return false;
        }

        $set = [];
        $par = [':id' => $id];
        if ($idea !== null && trim($idea) !== '') {
            $set[]      = 'idea = :i';
            $par[':i']  = mb_substr(trim($idea), 0, 1000);
        }
        if ($details !== null) {
            $new = trim($details);
            if ($appendDetails && $new !== '' && trim((string) ($row['details'] ?? '')) !== '') {
                $new = trim((string) $row['details']) . "\n\n" . $new;
            }
            $set[]     = 'details = :d';
            $par[':d'] = self::cleanDetails($new);
        }
        if ($priority !== null) {
            $set[]     = 'priority = :p';
            $par[':p'] = $priority;
        }
        if ($set !== []) {
            $this->db->prepare('UPDATE dev_ideas SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($par);
        }

        return true;   // exists — also when nothing changed (MySQL reports 0 rows for a no-op UPDATE)
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
            if (($i['priority'] ?? 'normal') === 'high') {
                $item['priority'] = 'high';
            }
            if (($i['details'] ?? null) !== null && $i['details'] !== '') {
                $item['details'] = (string) $i['details'];
            }
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

    /**
     * The ideas as one Markdown document (for the card's export button): status, priority, when
     * and who noted it, and the details — what a coding session needs to pick them up.
     *
     * @param list<array<string, mixed>> $ideas
     */
    public static function exportMarkdown(array $ideas, ?string $generatedOn = null): string
    {
        $on  = $generatedOn ?? gmdate('Y-m-d');
        $out = "# Kachow dev ideas — " . count($ideas) . ($ideas === [] || count($ideas) > 1 ? ' ideas' : ' idea') . " ({$on})\n";
        foreach ($ideas as $i) {
            $ts   = strtotime((string) ($i['created_at'] ?? ''));
            $meta = [self::STATUSES[$i['status']] ?? (string) $i['status']];
            if (($i['priority'] ?? 'normal') === 'high') {
                $meta[] = 'high priority';
            }
            if ($ts !== false) {
                $meta[] = 'noted ' . date('j M Y', $ts);
            }
            if (!empty($i['from'])) {
                $meta[] = 'from ' . $i['from'];
            }
            $out .= "\n## #" . (int) $i['id'] . ' · ' . trim((string) $i['idea']) . "\n";
            $out .= '_' . implode(' · ', $meta) . "_\n";
            $details = trim((string) ($i['details'] ?? ''));
            $out    .= $details !== '' ? "\n" . $details . "\n" : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function row(array $r): array
    {
        $name = trim((string) ($r['name'] ?? ''));
        $row  = [
            'id'         => (int) $r['id'],
            'idea'       => (string) $r['idea'],
            'details'    => isset($r['details']) && trim((string) $r['details']) !== '' ? (string) $r['details'] : null,
            'priority'   => self::priority((string) ($r['priority'] ?? 'normal')),
            'status'     => self::status((string) $r['status']),
            'created_at' => (string) $r['created_at'],
            'user_id'    => (int) $r['user_id'],
        ];
        if (array_key_exists('name', $r)) {
            $row['from'] = $name !== '' ? $name : 'user #' . (int) $r['user_id'];
        }

        return $row;
    }

    /**
     * High priority first, then newest first (by id), done ideas keep their place in the order.
     *
     * @param list<array<string, mixed>> $ideas
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $ideas): array
    {
        usort($ideas, static function (array $a, array $b): int {
            return [$b['priority'] === 'high', $b['id']] <=> [$a['priority'] === 'high', $a['id']];
        });

        return $ideas;
    }

    private static function cleanDetails(?string $d): ?string
    {
        $d = $d === null ? '' : trim($d);

        return $d === '' ? null : mb_substr($d, 0, 8000);
    }

    private static function priority(string $p): string
    {
        return isset(self::PRIORITIES[$p]) ? $p : 'normal';
    }

    /** A stored status, or 'new' for anything unexpected (e.g. before the migration ran). */
    private static function status(string $s): string
    {
        return isset(self::STATUSES[$s]) ? $s : 'new';
    }
}
