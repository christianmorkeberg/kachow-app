<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * What the assistant changed or deleted, with the data as it was, so a mistake can be put back (the restore_change tool). Owner-scoped.
 * Tolerant of a missing table (before the migration): recording silently does nothing and available() says false.
 */
final class ChangeLog
{
    private PDO $db;
    private ?bool $ok = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public function available(): bool
    {
        if ($this->ok === null) {
            try {
                $this->db->query('SELECT 1 FROM change_log LIMIT 1');
                $this->ok = true;
            } catch (\Throwable) {
                $this->ok = false;
            }
        }

        return $this->ok;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>>|null $before
     * @param array<string, mixed>|list<array<string, mixed>>|null $after
     * @return int|null the log id (null when there is no log table)
     */
    public function record(int $userId, string $entity, ?int $entityId, string $action, string $summary, mixed $before, mixed $after = null, ?string $tool = null): ?int
    {
        if (!$this->available()) {
            return null;
        }
        try {
            $this->db->prepare(
                'INSERT INTO change_log (user_id, entity, entity_id, action, summary, before_json, after_json, tool)
                 VALUES (:u, :e, :eid, :a, :s, :b, :af, :t)'
            )->execute([
                ':u' => $userId, ':e' => $entity, ':eid' => $entityId, ':a' => $action === 'delete' ? 'delete' : 'update',
                ':s' => mb_substr($summary, 0, 255),
                ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                ':af' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                ':t' => $tool,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log('change_log: ' . $e->getMessage());

            return null;
        }
    }

    /** @return list<array<string, mixed>> newest first; restored ones are marked, not hidden */
    public function recent(int $userId, int $limit = 20, ?string $entity = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $sql = 'SELECT id, entity, entity_id, action, summary, tool, restored_at, created_at FROM change_log WHERE user_id = :u'
            . ($entity !== null ? ' AND entity = :e' : '') . ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit));
        $st = $this->db->prepare($sql);
        $st->execute($entity !== null ? [':u' => $userId, ':e' => $entity] : [':u' => $userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'entity' => (string) $r['entity'], 'action' => (string) $r['action'], 'summary' => (string) $r['summary'],
                'restored' => $r['restored_at'] !== null, 'when' => \App\Support\LocalTime::label((string) $r['created_at'], 'D j M Y H:i'),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null with before/after decoded */
    public function find(int $userId, int $id): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $st = $this->db->prepare('SELECT * FROM change_log WHERE id = :id AND user_id = :u');
        $st->execute([':id' => $id, ':u' => $userId]);
        $r = $st->fetch();
        if ($r === false) {
            return null;
        }
        $r['id']     = (int) $r['id'];
        $r['before'] = $r['before_json'] !== null ? json_decode((string) $r['before_json'], true) : null;
        $r['after']  = $r['after_json'] !== null ? json_decode((string) $r['after_json'], true) : null;

        return $r;
    }

    public function markRestored(int $userId, int $id): void
    {
        $this->db->prepare('UPDATE change_log SET restored_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $userId]);
    }
}
