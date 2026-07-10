<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * Expense receipts — header-level data (vendor, date, total, VAT, category), with
 * the image (if any) referenced by filename only (the file lives outside the
 * webroot). A receipt starts as a draft and is booked on confirm. Owner-scoped.
 */
final class Receipts
{
    /** The fixed category list (AI suggests one; user can change it). */
    public const CATEGORIES = [
        'Groceries/Supplies',
        'Meals & Entertainment',
        'Travel & Transport',
        'Office & Equipment',
        'Software & Subscriptions',
        'Fees & Bank',
        'Marketing',
        'Utilities & Phone',
        'Other',
    ];

    private const FIELDS = ['vendor', 'purchased_at', 'total', 'vat', 'currency', 'category', 'note'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Creates a draft receipt. $fields may include any of FIELDS plus file_ref/mime.
     *
     * @param array<string, mixed> $fields
     */
    public function create(int $userId, array $fields, string $source = 'manual'): int
    {
        $data = $this->clean($fields);
        $data['user_id']  = $userId;
        $data['source']   = $source === 'photo' ? 'photo' : 'manual';
        $data['status']   = 'draft';
        $data['file_ref'] = isset($fields['file_ref']) ? (string) $fields['file_ref'] : null;
        $data['mime']     = isset($fields['mime']) ? (string) $fields['mime'] : null;

        $cols = array_keys($data);
        $ph   = array_map(static fn (string $c): string => ':' . $c, $cols);
        $stmt = $this->db->prepare(
            'INSERT INTO receipts (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')'
        );
        $stmt->execute(array_combine($ph, array_values($data)));

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM receipts WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Updates allowed fields on a receipt, owner-scoped. Returns true if it exists.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $userId, int $id, array $fields): bool
    {
        $data = $this->clean($fields);
        if ($data === []) {
            return $this->get($userId, $id) !== null;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $params[':id'] = $id;
        $params[':u']  = $userId;

        $stmt = $this->db->prepare("UPDATE receipts SET {$set} WHERE id = :id AND user_id = :u");
        $stmt->execute($params);

        return $stmt->rowCount() >= 0 && $this->get($userId, $id) !== null;
    }

    public function confirm(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE receipts SET status = 'confirmed' WHERE id = :id AND user_id = :u");
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $this->get($userId, $id) !== null;
    }

    /** Deletes a receipt, returning its file_ref (for file cleanup) or null. */
    public function delete(int $userId, int $id): ?string
    {
        $row = $this->get($userId, $id);
        if ($row === null) {
            return null;
        }
        $this->db->prepare('DELETE FROM receipts WHERE id = :id AND user_id = :u')
            ->execute([':id' => $id, ':u' => $userId]);

        return $row['file_ref'] !== null ? (string) $row['file_ref'] : null;
    }

    /**
     * Renderable card for the chat widget (kind = receipt) from a receipt row.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function card(array $r): array
    {
        $id = (int) $r['id'];

        return [
            'kind'       => 'receipt',
            'id'         => $id,
            'status'     => (string) $r['status'],
            'source'     => (string) $r['source'],
            'has_image'  => $r['file_ref'] !== null,
            'image_url'  => $r['file_ref'] !== null ? '/api/receipt-file.php?id=' . $id : null,
            'vendor'     => $r['vendor'] !== null ? (string) $r['vendor'] : '',
            'date'       => $r['purchased_at'] !== null ? (string) $r['purchased_at'] : '',
            'total'      => $r['total'] !== null ? (float) $r['total'] : null,
            'vat'        => $r['vat'] !== null ? (float) $r['vat'] : null,
            'currency'   => (string) ($r['currency'] ?? 'DKK'),
            'category'   => $r['category'] !== null ? (string) $r['category'] : '',
            'note'       => $r['note'] !== null ? (string) $r['note'] : '',
            'categories' => self::CATEGORIES,
        ];
    }

    /** Snaps a free category to the closest allowed one (case-insensitive), else 'Other'. */
    public static function normalizeCategory(?string $category): ?string
    {
        $category = trim((string) $category);
        if ($category === '') {
            return null;
        }
        foreach (self::CATEGORIES as $c) {
            if (strcasecmp($c, $category) === 0) {
                return $c;
            }
        }
        foreach (self::CATEGORIES as $c) {
            if (stripos($c, $category) !== false || stripos($category, $c) !== false) {
                return $c;
            }
        }

        return 'Other';
    }

    /**
     * Whitelists + normalises incoming fields.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function clean(array $fields): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $fields)) {
                continue;
            }
            $v = $fields[$f];
            $out[$f] = match ($f) {
                'total', 'vat'   => ($v === null || $v === '') ? null : round((float) $v, 2),
                'purchased_at'   => $this->normalizeDate($v),
                'currency'       => strtoupper(mb_substr(trim((string) $v), 0, 3)) ?: 'DKK',
                'category'       => self::normalizeCategory($v !== null ? (string) $v : null),
                'vendor'         => mb_substr(trim((string) $v), 0, 160),
                'note'           => mb_substr(trim((string) $v), 0, 255),
                default          => $v,
            };
        }

        return $out;
    }

    private function normalizeDate(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}
