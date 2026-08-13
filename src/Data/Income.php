<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Income — issued invoices (public via NemHandel, or private) and other revenue.
 * The counterpart to Receipts (which is the expense side). issued_at drives the moms
 * period (faktureringsprincippet — output VAT is owed on the invoice/delivery date,
 * not when paid); paid_at tracks the cash so outstanding invoices (debitorer) can be
 * surfaced. An entry starts as a draft and is booked on confirm. Owner-scoped.
 *
 * DK-only, flat 25% moms: amount_ex_vat + vat = total; on a VAT-inclusive figure,
 * vat = total / 5. deriveVat() fills the missing pieces from whichever one is given.
 */
final class Income
{
    /** Standard Danish moms rate. */
    public const VAT_RATE = 0.25;

    public const LOCAL_TZ = 'Europe/Copenhagen';

    /** Kachow's own invoice-number series prefix for GENERATED private invoices. */
    public const SERIES_PREFIX = 'K';

    /** Loose income categories (AI suggests; user can change). Kept short on purpose. */
    public const CATEGORIES = ['Services', 'Goods', 'Subscription', 'Public sector', 'Other'];

    private const FIELDS = [
        'kind', 'doc_number', 'customer', 'issued_at', 'paid_at',
        'amount_ex_vat', 'vat', 'total', 'currency', 'category', 'note',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /** Local (Europe/Copenhagen) date 'Y-m-d' for today. */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y-m-d');
    }

    /**
     * Fills the missing money pieces from whichever is supplied, assuming DK 25% moms
     * on a VAT-liable sale. Priority: an explicit vat is respected; otherwise it's
     * derived. Returns [amount_ex_vat, vat, total], each rounded to 2 dp or null.
     *
     * - total only            → vat = total/5, ex = total − vat
     * - ex only               → vat = ex*0.25, total = ex + vat
     * - total + ex            → vat = total − ex
     * - vat + total           → ex  = total − vat
     * - vat + ex              → total = ex + vat
     * - nothing / vatable=false with only total → vat=0, ex=total (0-rated / non-VAT)
     *
     * @return array{0:?float,1:?float,2:?float}
     */
    public static function deriveVat(?float $ex, ?float $vat, ?float $total, bool $vatable = true): array
    {
        $r = static fn (?float $n): ?float => $n === null ? null : round($n, 2);

        if (!$vatable) {
            // Zero-rated / non-VAT income: no output VAT.
            $t = $total ?? $ex;
            return [$r($t), 0.0, $r($t)];
        }

        if ($ex !== null && $vat !== null) {
            return [$r($ex), $r($vat), $r($ex + $vat)];
        }
        if ($total !== null && $vat !== null) {
            return [$r($total - $vat), $r($vat), $r($total)];
        }
        if ($total !== null && $ex !== null) {
            return [$r($ex), $r($total - $ex), $r($total)];
        }
        if ($total !== null) {
            $v = $total * self::VAT_RATE / (1 + self::VAT_RATE); // total/5 at 25%
            return [$r($total - $v), $r($v), $r($total)];
        }
        if ($ex !== null) {
            $v = $ex * self::VAT_RATE;
            return [$r($ex), $r($v), $r($ex + $v)];
        }

        return [null, $r($vat), null];
    }

    /**
     * Creates a draft income entry. $fields may include any of FIELDS plus file_ref/mime.
     *
     * @param array<string, mixed> $fields
     */
    public function create(int $userId, array $fields, string $source = 'manual'): int
    {
        $data = $this->clean($fields);
        $data['user_id']  = $userId;
        $data['source']   = in_array($source, ['nemhandel', 'private', 'manual', 'photo'], true) ? $source : 'manual';
        $data['status']   = 'draft';
        $data['file_ref'] = isset($fields['file_ref']) ? (string) $fields['file_ref'] : null;
        $data['mime']     = isset($fields['mime']) ? (string) $fields['mime'] : null;

        $cols = array_keys($data);
        $ph   = array_map(static fn (string $c): string => ':' . $c, $cols);
        $stmt = $this->db->prepare(
            'INSERT INTO income (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')'
        );
        $stmt->execute(array_combine($ph, array_values($data)));

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM income WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Updates allowed fields, owner-scoped. Returns true if the row exists.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $userId, int $id, array $fields): bool
    {
        $data = $this->clean($fields);
        if ($data === []) {
            return $this->get($userId, $id) !== null;
        }
        $set    = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params[':' . $k] = $v;
        }
        $params[':id'] = $id;
        $params[':u']  = $userId;

        $stmt = $this->db->prepare("UPDATE income SET {$set} WHERE id = :id AND user_id = :u");
        $stmt->execute($params);

        return $this->get($userId, $id) !== null;
    }

    /** Books a draft (status → booked). Returns true if it exists. */
    public function book(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE income SET status = 'booked' WHERE id = :id AND user_id = :u");
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $this->get($userId, $id) !== null;
    }

    /** Marks an income entry paid on a date (defaults today). Returns true if it exists. */
    public function markPaid(int $userId, int $id, ?string $date = null): bool
    {
        $date = $date !== null && trim($date) !== '' ? date('Y-m-d', strtotime($date) ?: time()) : self::today();
        $stmt = $this->db->prepare('UPDATE income SET paid_at = :d WHERE id = :id AND user_id = :u');
        $stmt->execute([':d' => $date, ':id' => $id, ':u' => $userId]);

        return $this->get($userId, $id) !== null;
    }

    /** Deletes an income entry, returning its file_ref (for file cleanup) or null. */
    public function delete(int $userId, int $id): ?string
    {
        $row = $this->get($userId, $id);
        if ($row === null) {
            return null;
        }
        $this->db->prepare('DELETE FROM income WHERE id = :id AND user_id = :u')
            ->execute([':id' => $id, ':u' => $userId]);

        return $row['file_ref'] !== null ? (string) $row['file_ref'] : null;
    }

    /**
     * The next number in Kachow's OWN private-invoice series for a year, e.g.
     * "K-2026-001". Only spans doc_numbers that match this series (external/NemHandel
     * numbers are ignored), so the series stays internally continuous. Used by the
     * private-invoice generator (a later phase); recording keeps external numbers as-is.
     */
    public function nextInvoiceNumber(int $userId, ?int $year = null): string
    {
        $year   = $year ?? (int) (new DateTimeImmutable('now', new DateTimeZone(self::LOCAL_TZ)))->format('Y');
        $prefix = self::SERIES_PREFIX . '-' . $year . '-';
        $stmt   = $this->db->prepare(
            'SELECT doc_number FROM income WHERE user_id = :u AND doc_number LIKE :p'
        );
        $stmt->execute([':u' => $userId, ':p' => $prefix . '%']);

        $max = 0;
        foreach ($stmt->fetchAll() as $r) {
            $n = (int) substr((string) $r['doc_number'], strlen($prefix));
            if ($n > $max) {
                $max = $n;
            }
        }

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Reporting summary over a period (issued_at inclusive 'Y-m-d' or null). Totals
     * PER CURRENCY (never blended): ex-VAT, VAT (salgsmoms), gross. Plus outstanding
     * (booked but unpaid) and a per-(customer, currency) breakdown.
     *
     * @return array{
     *   items:array<int,array<string,mixed>>, count:int,
     *   currencies:array<int,array{currency:string,ex:float,vat:float,total:float,count:int}>,
     *   outstanding:array<int,array{currency:string,total:float,count:int}>,
     *   by_customer:array<int,array{customer:string,currency:string,total:float}>
     * }
     */
    public function summary(
        int $userId,
        ?string $from = null,
        ?string $to = null,
        string $status = 'booked'
    ): array {
        $where  = ['user_id = :u'];
        $params = [':u' => $userId];
        if ($status === 'booked' || $status === 'draft') {
            $where[]       = 'status = :st';
            $params[':st'] = $status;
        }
        if ($from !== null) {
            $where[]        = 'issued_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[]      = 'issued_at <= :to';
            $params[':to'] = $to;
        }

        $stmt = $this->db->prepare(
            'SELECT id, kind, doc_number, customer, issued_at, paid_at, amount_ex_vat, vat, total, currency, category, note, status
             FROM income WHERE ' . implode(' AND ', $where) . '
             ORDER BY issued_at DESC, id DESC LIMIT 300'
        );
        $stmt->execute($params);

        $items   = [];
        $byCur   = [];  // currency => [ex, vat, total, count]
        $unpaid  = [];  // currency => [total, count]
        $byCust  = [];  // "cust\x1Fcur" => [customer, currency, total]
        foreach ($stmt->fetchAll() as $r) {
            $ex   = (float) $r['amount_ex_vat'];
            $vat  = (float) $r['vat'];
            $tot  = (float) $r['total'];
            $cur  = (string) ($r['currency'] ?? 'DKK') ?: 'DKK';
            $cust = trim((string) ($r['customer'] ?? '')) ?: '—';
            $paid = $r['paid_at'] !== null && $r['paid_at'] !== '';

            $byCur[$cur] ??= ['ex' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'count' => 0];
            $byCur[$cur]['ex']    += $ex;
            $byCur[$cur]['vat']   += $vat;
            $byCur[$cur]['total'] += $tot;
            $byCur[$cur]['count']++;

            if (!$paid) {
                $unpaid[$cur] ??= ['total' => 0.0, 'count' => 0];
                $unpaid[$cur]['total'] += $tot;
                $unpaid[$cur]['count']++;
            }

            $ck = $cust . "\x1F" . $cur;
            $byCust[$ck] ??= ['customer' => $cust, 'currency' => $cur, 'total' => 0.0];
            $byCust[$ck]['total'] += $tot;

            $items[] = [
                'id'         => (int) $r['id'],
                'kind'       => (string) $r['kind'],
                'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : '',
                'customer'   => $r['customer'] !== null ? (string) $r['customer'] : '',
                'date'       => $r['issued_at'] !== null ? (string) $r['issued_at'] : '',
                'paid_at'    => $r['paid_at'] !== null ? (string) $r['paid_at'] : '',
                'paid'       => $paid,
                'ex'         => $ex,
                'vat'        => $vat,
                'total'      => $tot,
                'currency'   => $cur,
                'category'   => $r['category'] !== null ? (string) $r['category'] : '',
                'note'       => $r['note'] !== null ? (string) $r['note'] : '',
            ];
        }

        $currencies = [];
        foreach ($byCur as $cur => $agg) {
            $currencies[] = [
                'currency' => $cur,
                'ex'       => round($agg['ex'], 2),
                'vat'      => round($agg['vat'], 2),
                'total'    => round($agg['total'], 2),
                'count'    => $agg['count'],
            ];
        }
        usort($currencies, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $outstanding = [];
        foreach ($unpaid as $cur => $agg) {
            $outstanding[] = ['currency' => $cur, 'total' => round($agg['total'], 2), 'count' => $agg['count']];
        }
        usort($outstanding, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $breakdown = array_values(array_map(
            static fn (array $x): array => ['customer' => $x['customer'], 'currency' => $x['currency'], 'total' => round($x['total'], 2)],
            $byCust
        ));
        usort($breakdown, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'items'       => $items,
            'count'       => count($items),
            'currencies'  => $currencies,
            'outstanding' => $outstanding,
            'by_customer' => $breakdown,
        ];
    }

    /** Total output VAT (salgsmoms) on booked income in a period — for the moms card. */
    public function outputVat(int $userId, ?string $from, ?string $to): float
    {
        $where  = ['user_id = :u', "status = 'booked'"];
        $params = [':u' => $userId];
        if ($from !== null) {
            $where[]        = 'issued_at >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where[]      = 'issued_at <= :to';
            $params[':to'] = $to;
        }
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(vat),0) AS v FROM income WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Renderable card for a single income entry (kind = income).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function card(array $r): array
    {
        $id = (int) $r['id'];

        return [
            'kind'       => 'income',
            'id'         => $id,
            'status'     => (string) $r['status'],
            'source'     => (string) $r['source'],
            'entry_kind' => (string) $r['kind'],
            'has_image'  => $r['file_ref'] !== null,
            'image_url'  => $r['file_ref'] !== null ? '/api/income-file.php?id=' . $id : null,
            'doc_number' => $r['doc_number'] !== null ? (string) $r['doc_number'] : '',
            'customer'   => $r['customer'] !== null ? (string) $r['customer'] : '',
            'date'       => $r['issued_at'] !== null ? (string) $r['issued_at'] : '',
            'paid_at'    => $r['paid_at'] !== null ? (string) $r['paid_at'] : '',
            'paid'       => $r['paid_at'] !== null && $r['paid_at'] !== '',
            'ex'         => $r['amount_ex_vat'] !== null ? (float) $r['amount_ex_vat'] : null,
            'vat'        => $r['vat'] !== null ? (float) $r['vat'] : null,
            'total'      => $r['total'] !== null ? (float) $r['total'] : null,
            'currency'   => (string) ($r['currency'] ?? 'DKK'),
            'category'   => $r['category'] !== null ? (string) $r['category'] : '',
            'note'       => $r['note'] !== null ? (string) $r['note'] : '',
            'categories' => self::CATEGORIES,
        ];
    }

    /**
     * Summary card (kind = income_summary) for a period.
     *
     * @return array<string, mixed>
     */
    public function summaryCard(int $userId, ?string $from, ?string $to, string $title): array
    {
        $s = $this->summary($userId, $from, $to);

        return [
            'kind'        => 'income_summary',
            'title'       => $title,
            'currencies'  => $s['currencies'],
            'outstanding' => $s['outstanding'],
            'by_customer' => $s['by_customer'],
            'count'       => $s['count'],
            'items'       => $s['items'],
        ];
    }

    /** Snaps a free category to the closest allowed one, else 'Other' (null if empty). */
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
                'amount_ex_vat', 'vat', 'total' => ($v === null || $v === '') ? null : round((float) $v, 2),
                'issued_at', 'paid_at'          => $this->normalizeDate($v),
                'currency'                      => strtoupper(mb_substr(trim((string) $v), 0, 3)) ?: 'DKK',
                'category'                      => self::normalizeCategory($v !== null ? (string) $v : null),
                'kind'                          => ((string) $v === 'other') ? 'other' : 'invoice',
                'doc_number'                    => mb_substr(trim((string) $v), 0, 40),
                'customer'                      => mb_substr(trim((string) $v), 0, 160),
                'note'                          => mb_substr(trim((string) $v), 0, 255),
                default                         => $v,
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
