<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\Kr;
use PDO;

/**
 * The kassekladde (journal): every booked invoice, confirmed expense, owner drawing and manual bank movement in date order with a
 * running voucher number per calendar year (ÅÅÅÅ-NNNN), and the saldobalance (totals per account). Read-only; numbers are DERIVED from
 * date order each time (income by issue date, expenses by purchase date), so a back-dated entry renumbers the later ones in that year.
 * DKK only, like the rest of the books. Drafts (unbooked income, unconfirmed receipts) are not in it.
 */
final class Ledger
{
    public const CASH_ACCOUNTS = ['moms' => 'Moms til/fra SKAT', 'tax' => 'Skat', 'fee' => 'Bankgebyrer', 'deposit' => 'Indskud', 'other' => 'Øvrige posteringer'];

    /** Expense categories are stored in English (Receipts::CATEGORIES); the ledger shows them in Danish. */
    private const CATEGORY_DA = [
        'Groceries/Supplies' => 'Varer og materialer', 'Meals & Entertainment' => 'Måltider og repræsentation', 'Travel & Transport' => 'Rejser og transport',
        'Office & Equipment' => 'Kontor og udstyr', 'Software & Subscriptions' => 'Software og abonnementer', 'Fees & Bank' => 'Gebyrer og bank',
        'Marketing' => 'Markedsføring', 'Utilities & Phone' => 'El, internet og telefon', 'Other' => 'Øvrige udgifter',
    ];

    private const TYPE_ORDER = ['Salg' => 1, 'Udgift' => 2, 'Privat hævning' => 3, 'Bankpostering' => 4];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * @return list<array<string, mixed>> rows with raw numbers: voucher, date (ISO), type, text, account, ex, vat, total (signed: income +, outgoings −), receipt (bool), status
     */
    public function rows(int $userId, ?string $from, ?string $to): array
    {
        // Number per calendar year over the WHOLE year, so a filtered export carries the same voucher numbers as a full one.
        $lo = $from !== null ? substr($from, 0, 4) . '-01-01' : null;
        $hi = $to !== null ? substr($to, 0, 4) . '-12-31' : null;
        $all = array_merge($this->income($userId, $lo, $hi), $this->expenses($userId, $lo, $hi), $this->draws($userId, $lo, $hi), $this->cash($userId, $lo, $hi));
        usort($all, static fn (array $a, array $b): int => [$a['date'], self::TYPE_ORDER[$a['type']] ?? 9, $a['_id']] <=> [$b['date'], self::TYPE_ORDER[$b['type']] ?? 9, $b['_id']]);

        $n   = [];
        $out = [];
        foreach ($all as $r) {
            $year = substr($r['date'], 0, 4);
            $n[$year] = ($n[$year] ?? 0) + 1;
            $r['voucher'] = $year . '-' . str_pad((string) $n[$year], 4, '0', STR_PAD_LEFT);
            $r['entity_id'] = $r['_id'];
            unset($r['_id']);
            if (($from !== null && $r['date'] < $from) || ($to !== null && $r['date'] > $to)) {
                continue;
            }
            $out[] = $r;
        }

        return $out;
    }

    /**
     * The journal as export rows (Danish headings, decimal comma).
     *
     * @return list<array<string, string>>
     */
    public function table(int $userId, ?string $from, ?string $to): array
    {
        return array_map(static fn (array $r): array => [
            'bilagsnr' => $r['voucher'], 'dato' => Kr::date($r['date']), 'type' => $r['type'], 'tekst' => $r['text'], 'konto' => $r['account'],
            'ex_moms' => Kr::amount($r['ex']), 'moms' => Kr::amount($r['vat']), 'i_alt' => Kr::amount($r['total']),
            'bilag' => $r['receipt'] ? 'Ja' : 'Nej', 'status' => $r['status'],
        ], $this->rows($userId, $from, $to));
    }

    /**
     * Totals per account (saldobalance) with a count, ex-moms, moms and total, grouped Indtægter / Udgifter / Privat / Bank.
     *
     * @return list<array<string, string>>
     */
    public function trialBalance(int $userId, ?string $from, ?string $to): array
    {
        $sections = ['Salg' => 'Indtægter', 'Udgift' => 'Udgifter', 'Privat hævning' => 'Privat', 'Bankpostering' => 'Bank'];
        $acc      = [];
        foreach ($this->rows($userId, $from, $to) as $r) {
            $k = $r['type'] . '|' . $r['account'];
            $acc[$k] ??= ['sektion' => $sections[$r['type']] ?? $r['type'], 'konto' => $r['account'], 'antal' => 0, 'ex' => 0.0, 'vat' => 0.0, 'total' => 0.0, 'o' => self::TYPE_ORDER[$r['type']] ?? 9];
            $acc[$k]['antal']++;
            $acc[$k]['ex']    += $r['ex'];
            $acc[$k]['vat']   += $r['vat'];
            $acc[$k]['total'] += $r['total'];
        }
        uasort($acc, static fn (array $a, array $b): int => [$a['o'], $a['konto']] <=> [$b['o'], $b['konto']]);

        return array_values(array_map(static fn (array $a): array => [
            'sektion' => $a['sektion'], 'konto' => $a['konto'], 'antal' => (string) $a['antal'],
            'ex_moms' => Kr::amount($a['ex']), 'moms' => Kr::amount($a['vat']), 'i_alt' => Kr::amount($a['total']),
        ], $acc));
    }

    /** @return list<array<string, mixed>> */
    private function income(int $userId, ?string $lo, ?string $hi): array
    {
        [$w, $p] = $this->range('issued_at', $lo, $hi, ["user_id = :u", "status = 'booked'", "currency = 'DKK'", 'issued_at IS NOT NULL'], $userId);
        $stmt = $this->db->prepare("SELECT id, doc_number, customer, issued_at, paid_at, amount_ex_vat, vat, total, category, file_ref, note FROM income WHERE {$w}");
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                '_id' => (int) $r['id'], 'entity' => 'income', 'date' => (string) $r['issued_at'], 'type' => 'Salg',
                'text' => trim(((string) ($r['doc_number'] ?? '') !== '' ? $r['doc_number'] . ' · ' : '') . (string) ($r['customer'] ?? '') . ((string) ($r['note'] ?? '') !== '' ? ' — ' . $r['note'] : '')),
                'account' => (string) ($r['category'] ?? '') !== '' ? (string) $r['category'] : 'Salg af ydelser',
                'ex' => round((float) $r['amount_ex_vat'], 2), 'vat' => round((float) $r['vat'], 2), 'total' => round((float) $r['total'], 2),
                'receipt' => (string) ($r['file_ref'] ?? '') !== '', 'file_ref' => (string) ($r['file_ref'] ?? ''), 'status' => $r['paid_at'] !== null && $r['paid_at'] !== '' ? 'Betalt ' . Kr::date((string) $r['paid_at']) : 'Ikke betalt',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function expenses(int $userId, ?string $lo, ?string $hi): array
    {
        [$w, $p] = $this->range('purchased_at', $lo, $hi, ["user_id = :u", "status = 'confirmed'", "currency = 'DKK'", 'purchased_at IS NOT NULL'], $userId);
        $stmt = $this->db->prepare("SELECT id, vendor, purchased_at, total, vat, category, note, file_ref, paid_privately, reimbursed_at FROM receipts WHERE {$w}");
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $total = round((float) $r['total'], 2);
            $vat   = round((float) $r['vat'], 2);
            $priv  = (int) ($r['paid_privately'] ?? 0) === 1;
            $out[] = [
                '_id' => (int) $r['id'], 'entity' => 'expense', 'date' => (string) $r['purchased_at'], 'type' => 'Udgift',
                'text' => trim((string) ($r['vendor'] ?? '') . ((string) ($r['note'] ?? '') !== '' ? ' — ' . $r['note'] : '')),
                'account' => (string) ($r['category'] ?? '') !== '' ? (self::CATEGORY_DA[(string) $r['category']] ?? ucfirst((string) $r['category'])) : 'Øvrige udgifter',
                'ex' => -round($total - $vat, 2), 'vat' => -$vat, 'total' => -$total,
                'receipt' => (string) ($r['file_ref'] ?? '') !== '', 'file_ref' => (string) ($r['file_ref'] ?? ''),
                'status' => !$priv ? 'Betalt af virksomheden' : ($r['reimbursed_at'] !== null && $r['reimbursed_at'] !== '' ? 'Udlæg tilbagebetalt ' . Kr::date((string) $r['reimbursed_at']) : 'Udlæg – skyldig til ejer'),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function draws(int $userId, ?string $lo, ?string $hi): array
    {
        [$w, $p] = $this->range('drawn_at', $lo, $hi, ['user_id = :u', "currency = 'DKK'"], $userId);
        $stmt = $this->db->prepare("SELECT id, drawn_at, amount, note FROM owner_draws WHERE {$w}");
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $a = round((float) $r['amount'], 2);
            $out[] = ['_id' => (int) $r['id'], 'date' => (string) $r['drawn_at'], 'type' => 'Privat hævning', 'text' => (string) ($r['note'] ?? ''), 'account' => 'Privat hævning',
                'ex' => -$a, 'vat' => 0.0, 'total' => -$a, 'receipt' => false, 'status' => 'Betalt'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function cash(int $userId, ?string $lo, ?string $hi): array
    {
        [$w, $p] = $this->range('occurred_at', $lo, $hi, ['user_id = :u'], $userId);
        $stmt = $this->db->prepare("SELECT id, occurred_at, direction, amount, category, note FROM cash_entries WHERE {$w}");
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $a = round((float) $r['amount'], 2) * ($r['direction'] === 'in' ? 1 : -1);
            $out[] = ['_id' => (int) $r['id'], 'date' => (string) $r['occurred_at'], 'type' => 'Bankpostering', 'text' => (string) ($r['note'] ?? ''),
                'account' => self::CASH_ACCOUNTS[(string) $r['category']] ?? self::CASH_ACCOUNTS['other'], 'ex' => $a, 'vat' => 0.0, 'total' => $a, 'receipt' => false, 'status' => 'Betalt'];
        }

        return $out;
    }

    /** @return array{0:string, 1:array<string, mixed>} */
    private function range(string $col, ?string $lo, ?string $hi, array $where, int $userId): array
    {
        $p = [':u' => $userId];
        if ($lo !== null) {
            $where[] = "{$col} >= :lo";
            $p[':lo'] = $lo;
        }
        if ($hi !== null) {
            $where[] = "{$col} <= :hi";
            $p[':hi'] = $hi;
        }

        return [implode(' AND ', $where), $p];
    }
}
