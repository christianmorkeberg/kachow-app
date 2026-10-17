<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Every push the app sends, kept so it can be read again from the in-app list (the bell) — also
 * when the push itself was missed, dismissed or never delivered. Written by Notifier::notify().
 */
final class NotificationHistory
{
    public const RETENTION_DAYS = 60;
    public const MAX_LIST       = 100;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public function add(int $userId, string $type, string $title, string $body, ?string $url): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO notification_history (user_id, type, title, body, url) VALUES (:u, :t, :ti, :b, :url)'
        );
        $stmt->execute([
            ':u' => $userId, ':t' => mb_substr($type, 0, 32), ':ti' => mb_substr($title, 0, 200),
            ':b' => mb_substr($body, 0, 2000), ':url' => $url !== null ? mb_substr($url, 0, 255) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Newest first. $beforeId pages further back.
     *
     * @return list<array{id:int, type:string, title:string, body:string, url:?string, at:string, read:bool}>
     */
    public function list(int $userId, int $limit = 50, ?int $beforeId = null): array
    {
        $limit = max(1, min(self::MAX_LIST, $limit));
        $sql   = 'SELECT id, type, title, body, url, created_at, read_at FROM notification_history WHERE user_id = :u'
            . ($beforeId !== null ? ' AND id < :before' : '') . ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $args = [':u' => $userId] + ($beforeId !== null ? [':before' => $beforeId] : []);
        $stmt->execute($args);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'    => (int) $r['id'],
                'type'  => (string) $r['type'],
                'title' => (string) $r['title'],
                'body'  => (string) $r['body'],
                'url'   => $r['url'] !== null ? (string) $r['url'] : null,
                // DB TIMESTAMP in the session zone (see conversations.php): the client reads it as local time.
                'at'    => (string) $r['created_at'],
                'read'  => $r['read_at'] !== null,
            ];
        }

        return $out;
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notification_history WHERE user_id = :u AND read_at IS NULL');
        $stmt->execute([':u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE notification_history SET read_at = :at WHERE id = :id AND user_id = :u AND read_at IS NULL'
        );
        $stmt->execute([':at' => gmdate('Y-m-d H:i:s'), ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function markAllRead(int $userId): int
    {
        $stmt = $this->db->prepare('UPDATE notification_history SET read_at = :at WHERE user_id = :u AND read_at IS NULL');
        $stmt->execute([':at' => gmdate('Y-m-d H:i:s'), ':u' => $userId]);

        return $stmt->rowCount();
    }

    /** Opening what a notification points to counts as reading it (every entry with that exact link). */
    public function markReadByUrl(int $userId, string $url): int
    {
        $stmt = $this->db->prepare(
            'UPDATE notification_history SET read_at = :at WHERE user_id = :u AND url = :url AND read_at IS NULL'
        );
        $stmt->execute([':at' => gmdate('Y-m-d H:i:s'), ':u' => $userId, ':url' => $url]);

        return $stmt->rowCount();
    }

    /** Removes this user's entries past the retention window. Returns rows removed. */
    public function purgeOld(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM notification_history WHERE user_id = :u AND created_at < :cut');
        $stmt->execute([':u' => $userId, ':cut' => date('Y-m-d H:i:s', time() - self::RETENTION_DAYS * 86400)]);

        return $stmt->rowCount();
    }
}
