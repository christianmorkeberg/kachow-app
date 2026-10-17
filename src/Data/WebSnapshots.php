<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The last readable text of each web page a user's assistant opened (see Tools\FetchWebpage),
 * so the next look at the same page can report what changed — the basis of website watching.
 */
final class WebSnapshots
{
    public const MAX_TEXT_CHARS = 100_000;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return array{text: string, content_hash: string, fetched_at: string}|null fetched_at is UTC */
    public function get(int $userId, string $url): ?array
    {
        $stmt = $this->db->prepare('SELECT text, content_hash, fetched_at FROM web_snapshots WHERE user_id = :u AND url_hash = :h');
        $stmt->execute([':u' => $userId, ':h' => self::key($url)]);
        $r = $stmt->fetch();

        return $r === false ? null : [
            'text'         => (string) $r['text'],
            'content_hash' => (string) $r['content_hash'],
            'fetched_at'   => (string) $r['fetched_at'],
        ];
    }

    public function save(int $userId, string $url, string $text): void
    {
        $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        $args = [
            ':u' => $userId, ':h' => self::key($url), ':url' => mb_substr($url, 0, 2048),
            ':c' => hash('sha256', $text), ':t' => $text, ':at' => gmdate('Y-m-d H:i:s'),
        ];
        $upd = $this->db->prepare('UPDATE web_snapshots SET url = :url, content_hash = :c, text = :t, fetched_at = :at WHERE user_id = :u AND url_hash = :h');
        $upd->execute($args);
        if ($upd->rowCount() > 0 || $this->get($userId, $url) !== null) {
            return;
        }
        $this->db->prepare('INSERT INTO web_snapshots (user_id, url_hash, url, content_hash, text, fetched_at) VALUES (:u, :h, :url, :c, :t, :at)')
            ->execute($args);
    }

    /** Same page, same key: scheme and a trailing slash or fragment don't make it a different page. */
    public static function key(string $url): string
    {
        $u = strtolower(trim($url));
        $u = (string) preg_replace('~^https?://~', '', $u);
        $u = (string) preg_replace('~#.*$~', '', $u);

        return hash('sha256', rtrim($u, '/'));
    }
}
