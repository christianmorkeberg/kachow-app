<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Append-only audit trail for bookkeeping entities (income, expenses, draws) — the
 * kontrolspor that mimics bogføringsloven. Every create/update/book/paid/reimburse/
 * delete on a booked entity gets a row here. Rows are NEVER edited or deleted; this
 * class deliberately exposes only append + read.
 *
 * We chose the "softer immutability" model: booked entries can still be corrected in
 * place, but each change is trailed here, so there is always a record of what changed.
 */
final class BookkeepingAudit
{
    public const TYPES   = ['income', 'expense', 'draw'];
    public const ACTIONS = ['create', 'update', 'book', 'paid', 'reimburse', 'delete'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Records one audit entry. $detail is an optional structured snapshot / changed
     * fields (json-encoded). Owner-scoped. Best-effort: a logging failure must never
     * break the actual bookkeeping action, so callers may ignore exceptions.
     *
     * @param array<string, mixed>|null $detail
     */
    public function log(int $userId, string $entityType, int $entityId, string $action, ?array $detail = null): void
    {
        $type = in_array($entityType, self::TYPES, true) ? $entityType : 'income';
        $act  = in_array($action, self::ACTIONS, true) ? $action : 'update';
        $json = $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $this->db->prepare(
            'INSERT INTO bookkeeping_audit (user_id, entity_type, entity_id, action, detail)
             VALUES (:u, :t, :e, :a, :d)'
        );
        $stmt->execute([':u' => $userId, ':t' => $type, ':e' => $entityId, ':a' => $act, ':d' => $json]);
    }

    /**
     * The trail for one entity (oldest first), owner-scoped.
     *
     * @return array<int, array{action:string, detail:?array<string,mixed>, at:string}>
     */
    public function forEntity(int $userId, string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT action, detail, created_at FROM bookkeeping_audit
             WHERE user_id = :u AND entity_type = :t AND entity_id = :e
             ORDER BY id ASC'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $detail = null;
            if ($r['detail'] !== null && $r['detail'] !== '') {
                $decoded = json_decode((string) $r['detail'], true);
                $detail  = is_array($decoded) ? $decoded : null;
            }
            $out[] = [
                'action' => (string) $r['action'],
                'detail' => $detail,
                'at'     => (string) $r['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Recent audit rows across all entities for the user (newest first) — for the
     * audit-log surface / accountant review. Bounded.
     *
     * @return array<int, array{entity_type:string, entity_id:int, action:string, at:string}>
     */
    public function recent(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt  = $this->db->prepare(
            'SELECT entity_type, entity_id, action, created_at FROM bookkeeping_audit
             WHERE user_id = :u ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => (string) $r['entity_type'],
                'entity_id'   => (int) $r['entity_id'],
                'action'      => (string) $r['action'],
                'at'          => (string) $r['created_at'],
            ];
        }

        return $out;
    }
}
