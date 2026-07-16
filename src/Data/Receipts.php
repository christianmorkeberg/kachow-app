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

        // Line items (from a photo read) are stored as JSON; null when none.
        if (isset($fields['line_items']) && is_array($fields['line_items']) && $fields['line_items'] !== []) {
            $data['line_items'] = json_encode(array_values($fields['line_items']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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
            'line_items' => self::decodeLineItems($r['line_items'] ?? null),
            'categories' => self::CATEGORIES,
        ];
    }

    /**
     * Decodes the stored line_items JSON into a clean list for the card. Tolerant of
     * null/garbage (returns []).
     *
     * @return array<int, array{description:string, qty:?float, amount:?float}>
     */
    private static function decodeLineItems(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['description'])) {
                continue;
            }
            $out[] = [
                'description' => (string) $item['description'],
                'qty'         => isset($item['qty']) && is_numeric($item['qty']) ? (float) $item['qty'] : null,
                'amount'      => isset($item['amount']) && is_numeric($item['amount']) ? (float) $item['amount'] : null,
            ];
        }

        return $out;
    }

    /**
     * Filtered expense summary for reporting: matching receipts (newest first),
     * totals PER CURRENCY (never blended), and a per (category, currency)
     * breakdown. Dates are inclusive 'Y-m-d' or null.
     *
     * @return array{
     *   items:array<int,array<string,mixed>>, count:int,
     *   currencies:array<int,array{currency:string,total:float,vat:float,count:int}>,
     *   by_category:array<int,array{category:string,currency:string,total:float}>
     * }
     */
    public function summary(
        int $userId,
        ?string $from = null,
        ?string $to = null,
        ?string $category = null,
        string $status = 'confirmed'
    ): array {
        $where  = ['user_id = :u'];
        $params = [':u' => $userId];
        if ($status === 'confirmed' || $status === 'draft') {
            $where[]      = 'status = :st';
            $params[':st'] = $status;
        }
        if ($from !== null) {
            $where[]        = 'purchased_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[]      = 'purchased_at <= :to';
            $params[':to'] = $to;
        }
        if ($category !== null && $category !== '') {
            $where[]       = 'category = :cat';
            $params[':cat'] = $category;
        }

        $stmt = $this->db->prepare(
            'SELECT id, vendor, purchased_at, total, vat, currency, category, note, status
             FROM receipts WHERE ' . implode(' AND ', $where) . '
             ORDER BY purchased_at DESC, id DESC LIMIT 300'
        );
        $stmt->execute($params);

        $items    = [];
        $byCur    = [];  // currency => [total, vat, count]
        $byCatCur = [];  // "cat\x1Fcur" => [category, currency, total]
        foreach ($stmt->fetchAll() as $r) {
            $t   = (float) $r['total'];
            $v   = (float) $r['vat'];
            $cur = (string) ($r['currency'] ?? 'DKK') ?: 'DKK';
            $cat = (string) ($r['category'] ?? '') ?: 'Other';

            $byCur[$cur] ??= ['total' => 0.0, 'vat' => 0.0, 'count' => 0];
            $byCur[$cur]['total'] += $t;
            $byCur[$cur]['vat']   += $v;
            $byCur[$cur]['count']++;

            $key = $cat . "\x1F" . $cur;
            $byCatCur[$key] ??= ['category' => $cat, 'currency' => $cur, 'total' => 0.0];
            $byCatCur[$key]['total'] += $t;

            $items[] = [
                'id'       => (int) $r['id'],
                'vendor'   => $r['vendor'] !== null ? (string) $r['vendor'] : '',
                'date'     => $r['purchased_at'] !== null ? (string) $r['purchased_at'] : '',
                'total'    => $t,
                'vat'      => $v,
                'currency' => $cur,
                'category' => $cat,
                'note'     => $r['note'] !== null ? (string) $r['note'] : '',
            ];
        }

        $currencies = [];
        foreach ($byCur as $cur => $agg) {
            $currencies[] = ['currency' => $cur, 'total' => round($agg['total'], 2), 'vat' => round($agg['vat'], 2), 'count' => $agg['count']];
        }
        usort($currencies, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $breakdown = array_values(array_map(
            static fn (array $x): array => ['category' => $x['category'], 'currency' => $x['currency'], 'total' => round($x['total'], 2)],
            $byCatCur
        ));
        usort($breakdown, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'items'       => $items,
            'count'       => count($items),
            'currencies'  => $currencies,
            'by_category' => $breakdown,
        ];
    }

    /**
     * Finds an existing receipt that looks like a duplicate of the given one —
     * same vendor (case-insensitive) + date + amount — excluding $excludeId.
     * Prefers a confirmed match. Returns a compact record or null.
     *
     * @return array{id:int, vendor:string, date:string, total:float, currency:string, status:string}|null
     */
    public function findDuplicate(int $userId, ?string $vendor, ?string $date, ?float $total, ?int $excludeId = null): ?array
    {
        $vendor = trim((string) $vendor);
        $date   = trim((string) $date);
        if ($vendor === '' || $date === '' || $total === null) {
            return null;
        }

        $sql = 'SELECT id, vendor, purchased_at, total, currency, status
                FROM receipts
                WHERE user_id = :u AND LOWER(vendor) = LOWER(:v)
                  AND purchased_at = :d AND ABS(total - :t) < 0.005';
        $params = [':u' => $userId, ':v' => $vendor, ':d' => $date, ':t' => $total];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :ex';
            $params[':ex'] = $excludeId;
        }
        $sql .= ' ORDER BY (status = "confirmed") DESC, id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'id'       => (int) $row['id'],
            'vendor'   => (string) $row['vendor'],
            'date'     => (string) $row['purchased_at'],
            'total'    => (float) $row['total'],
            'currency' => (string) ($row['currency'] ?? 'DKK'),
            'status'   => (string) $row['status'],
        ];
    }

    /**
     * Card for a receipt row plus a `duplicate` field if a look-alike already
     * exists (so the panel can warn). Use this when building a draft's card.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function cardWithChecks(int $userId, array $r): array
    {
        $card = $this->card($r);
        $dup  = $this->findDuplicate(
            $userId,
            $r['vendor'] ?? null,
            $r['purchased_at'] ?? null,
            $r['total'] !== null ? (float) $r['total'] : null,
            (int) $r['id']
        );
        if ($dup !== null) {
            $card['duplicate'] = ['date' => $dup['date'], 'vendor' => $dup['vendor'], 'confirmed' => $dup['status'] === 'confirmed'];
        }

        return $card;
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
