<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Data-access layer for `feedback_reports` — messages users flag to the developer when
 * something looks off. A JSON snapshot (the message + a little surrounding context + its
 * diagnostics) is stored so the report is self-contained even if the conversation is
 * later deleted.
 */
final class FeedbackReports
{
    private const STATUSES = ['new', 'seen', 'resolved'];

    /** The statuses that still need the developer: everything not resolved. */
    private const OPEN = ['new', 'seen'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Stores a report. $snapshot is an arbitrary array (serialised to JSON). Returns
     * the new report id.
     *
     * @param array<string, mixed> $snapshot
     */
    public function create(
        int $userId,
        ?int $conversationId,
        ?int $messageId,
        ?string $note,
        array $snapshot
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO feedback_reports (user_id, conversation_id, message_id, note, snapshot)
             VALUES (:u, :c, :m, :note, :snap)'
        );
        $stmt->execute([
            ':u'    => $userId,
            ':c'    => $conversationId,
            ':m'    => $messageId,
            ':note' => ($note !== null && trim($note) !== '') ? mb_substr(trim($note), 0, 2000) : null,
            ':snap' => (string) json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Recent reports, optionally filtered: a status, or "open" (new + seen = not resolved yet).
     * Unresolved reports always come first, newest first within each group, so what still needs
     * attention is at the top even in the unfiltered list. Joins the reporter's name/email.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(?string $status = null, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT r.id, r.user_id, r.conversation_id, r.message_id, r.note, r.snapshot,
                       r.status, r.created_at, u.name AS reporter_name, u.email AS reporter_email
                FROM feedback_reports r JOIN users u ON u.id = r.user_id';
        $params = [];
        if ($status === 'open') {
            $sql .= " WHERE r.status <> 'resolved'";
        } elseif ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE r.status = :s';
            $params[':s'] = $status;
        }
        $sql .= " ORDER BY CASE WHEN r.status = 'resolved' THEN 1 ELSE 0 END, r.id DESC LIMIT " . $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One report by id, with the reporter's name/email joined, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT r.id, r.user_id, r.conversation_id, r.message_id, r.note, r.snapshot,
                    r.status, r.created_at, u.name AS reporter_name, u.email AS reporter_email
             FROM feedback_reports r JOIN users u ON u.id = r.user_id
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Count of reports in a given status (default 'new'). */
    public function countByStatus(string $status = 'new'): int
    {
        if (!in_array($status, self::STATUSES, true)) {
            return 0;
        }
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM feedback_reports WHERE status = :s');
        $stmt->execute([':s' => $status]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many reports are open (not resolved) and resolved — the numbers on the card's filter chips.
     *
     * @return array{open:int, resolved:int, all:int}
     */
    public function counts(): array
    {
        $resolved = $this->countByStatus('resolved');
        $total    = (int) $this->db->query('SELECT COUNT(*) FROM feedback_reports')->fetchColumn();

        return ['open' => $total - $resolved, 'resolved' => $resolved, 'all' => $total];
    }

    /**
     * Sets a report's status. Returns true if the report exists — including when it already has that status
     * (MySQL reports 0 changed rows for a no-op UPDATE, which used to read as "No such report" when resolving a
     * report that was already resolved, e.g. from a card that had gone stale).
     */
    public function setStatus(int $reportId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $cur = $this->db->prepare('SELECT status FROM feedback_reports WHERE id = :id');
        $cur->execute([':id' => $reportId]);
        $current = $cur->fetchColumn();
        if ($current === false) {
            return false;
        }
        if ((string) $current !== $status) {
            $this->db->prepare('UPDATE feedback_reports SET status = :s WHERE id = :id')
                ->execute([':s' => $status, ':id' => $reportId]);
        }

        return true;
    }
}
