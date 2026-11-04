<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Receipts\ReceiptStorage;
use PDO;

/** Extra files on an income or expense entry (a timesheet behind an invoice, a contract …). Owner-scoped; the caller checks the entry is the user's. */
final class EntryAttachments
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** @return int the new attachment id */
    public function add(int $userId, string $type, int $entityId, string $fileRef, string $mime, string $name, int $size): int
    {
        $type = $type === 'income' ? 'income' : 'expense';
        $this->db->prepare('INSERT INTO entry_attachments (user_id, entity_type, entity_id, file_ref, mime, name, size) VALUES (:u, :t, :e, :f, :m, :n, :s)')
            ->execute([':u' => $userId, ':t' => $type, ':e' => $entityId, ':f' => $fileRef, ':m' => $mime, ':n' => mb_substr($name, 0, 160), ':s' => max(0, $size)]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array{id:int, name:string, mime:string, size:int, at:string}> */
    public function listFor(int $userId, string $type, int $entityId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, mime, size, created_at FROM entry_attachments WHERE user_id = :u AND entity_type = :t AND entity_id = :e ORDER BY id');
        $stmt->execute([':u' => $userId, ':t' => $type === 'income' ? 'income' : 'expense', ':e' => $entityId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'mime' => (string) $r['mime'], 'size' => (int) $r['size'], 'at' => (string) $r['created_at']], $stmt->fetchAll());
    }

    /** @return array{id:int, entity_type:string, entity_id:int, file_ref:string, mime:string, name:string}|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, entity_type, entity_id, file_ref, mime, name FROM entry_attachments WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();

        return $r === false ? null : ['id' => (int) $r['id'], 'entity_type' => (string) $r['entity_type'], 'entity_id' => (int) $r['entity_id'], 'file_ref' => (string) $r['file_ref'], 'mime' => (string) $r['mime'], 'name' => (string) $r['name']];
    }

    /** Removes one attachment row; returns its stored file ref so the caller can delete the file. */
    public function delete(int $userId, int $id): ?string
    {
        $row = $this->get($userId, $id);
        if ($row === null) {
            return null;
        }
        $this->db->prepare('DELETE FROM entry_attachments WHERE id = :id AND user_id = :u')->execute([':id' => $id, ':u' => $userId]);

        return $row['file_ref'];
    }

    /** The entry is being deleted: drop its attachment rows AND files (best effort — never blocks the delete). */
    public function purgeFor(int $userId, string $type, int $entityId, ?ReceiptStorage $storage = null): void
    {
        try {
            $storage ??= new ReceiptStorage();
            foreach ($this->listRefs($userId, $type, $entityId) as $ref) {
                $storage->delete($userId, $ref);
            }
            $this->db->prepare('DELETE FROM entry_attachments WHERE user_id = :u AND entity_type = :t AND entity_id = :e')
                ->execute([':u' => $userId, ':t' => $type === 'income' ? 'income' : 'expense', ':e' => $entityId]);
        } catch (\Throwable $e) {
            error_log('EntryAttachments::purgeFor: ' . $e->getMessage());
        }
    }

    /** @return list<string> */
    private function listRefs(int $userId, string $type, int $entityId): array
    {
        $stmt = $this->db->prepare('SELECT file_ref FROM entry_attachments WHERE user_id = :u AND entity_type = :t AND entity_id = :e');
        $stmt->execute([':u' => $userId, ':t' => $type === 'income' ? 'income' : 'expense', ':e' => $entityId]);

        return array_map(static fn (array $r): string => (string) $r['file_ref'], $stmt->fetchAll());
    }
}
