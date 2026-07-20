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
     * Recent reports, newest first, optionally filtered by status. Joins the reporter's
     * name/email for display.
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
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE r.status = :s';
            $params[':s'] = $status;
        }
        $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
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

    /** Sets a report's status. Returns true if the row exists. */
    public function setStatus(int $reportId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = $this->db->prepare('UPDATE feedback_reports SET status = :s WHERE id = :id');
        $stmt->execute([':s' => $status, ':id' => $reportId]);

        return $stmt->rowCount() > 0;
    }
}
