<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\Kr;
use PDO;

/**
 * Status / balance sheet (balance) pr. a date for an enkeltmandsvirksomhed, in Danish. Derived from what Kachow holds:
 *   Aktiver:  bank (computed from the cash trail, or a stated balance entered by hand), tilgodehavender (invoices issued and not yet paid),
 *             moms owed BY SKAT, plus hand-entered assets (udstyr …).
 *   Passiver: skyldig moms, udlæg owed to the owner, hand-entered liabilities (lån …), and egenkapital = aktiver − gæld (the balancing figure).
 * Not an audited statement; the hand-entered items and the opening bank balance are the user's own figures. DKK only.
 */
final class BalanceSheet
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * @param list<array{kind:string, label:string, amount:float, as_of:string, note:string}> $items BalanceItems::inForce()
     * @return array{rows:list<array<string,string>>, assets:float, liabilities:float, equity:float, bank_source:string, as_of:string}
     */
    public function asOf(int $userId, string $date, float $opening, array $items): array
    {
        $received = $this->sum('SELECT COALESCE(SUM(total),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND paid_at IS NOT NULL AND paid_at <= :d', $userId, $date);
        $spent    = $this->sum('SELECT COALESCE(SUM(total),0) FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\' AND purchased_at <= :d
                                AND (paid_privately = 0 OR (reimbursed_at IS NOT NULL AND reimbursed_at <= :d2))', $userId, $date, true);
        $drawn    = $this->sum('SELECT COALESCE(SUM(amount),0) FROM owner_draws WHERE user_id=:u AND currency=\'DKK\' AND drawn_at <= :d', $userId, $date);
        $in       = $this->sum('SELECT COALESCE(SUM(amount),0) FROM cash_entries WHERE user_id=:u AND direction=\'in\' AND occurred_at <= :d', $userId, $date);
        $out      = $this->sum('SELECT COALESCE(SUM(amount),0) FROM cash_entries WHERE user_id=:u AND direction=\'out\' AND occurred_at <= :d', $userId, $date);
        $computed = round($opening + $received + $in - $spent - $drawn - $out, 2);

        $receivables = $this->sum('SELECT COALESCE(SUM(total),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at <= :d
                                   AND (paid_at IS NULL OR paid_at > :d2)', $userId, $date, true);
        $outVat   = $this->sum('SELECT COALESCE(SUM(vat),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at <= :d', $userId, $date);
        $inVat    = $this->sum('SELECT COALESCE(SUM(vat),0) FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\' AND purchased_at <= :d', $userId, $date);
        $momsPaid = $this->sum('SELECT COALESCE(SUM(CASE WHEN direction=\'in\' THEN amount ELSE -amount END),0) FROM cash_entries WHERE user_id=:u AND category=\'moms\' AND occurred_at <= :d', $userId, $date);
        $momsNet  = round($outVat - $inVat + $momsPaid, 2);   // + = skyldig til SKAT, − = tilgodehavende hos SKAT
        $udlaeg   = $this->sum('SELECT COALESCE(SUM(total),0) FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\' AND paid_privately = 1 AND purchased_at <= :d
                                AND (reimbursed_at IS NULL OR reimbursed_at > :d2)', $userId, $date, true);

        $bank       = $computed;
        $bankSource = 'beregnet ud fra bogføringen og åbningssaldo';
        $assets     = [];
        $liab       = [];
        foreach ($items as $i) {
            if ($i['kind'] === 'asset' && preg_match('/^bank/i', $i['label']) === 1) {
                $bank       = $i['amount'];            // a stated balance (from the bank statement) beats the computed one
                $bankSource = 'oplyst saldo pr. ' . Kr::date($i['as_of']);
                continue;
            }
            $i['kind'] === 'asset' ? $assets[] = $i : $liab[] = $i;
        }

        $rows = [];
        $add  = static function (string $section, string $post, float $amount, string $note = '') use (&$rows): void {
            $rows[] = ['sektion' => $section, 'post' => $post, 'beloeb' => Kr::amount($amount), 'note' => $note];
        };
        $post = static function (string $label, float $v, string $note, array &$sum) use ($add): void {
            $add('Aktiver', $label, $v, $note);
            $sum[0] += $v;
        };
        $s = [0.0];
        $post('Bankindestående', $bank, $bankSource, $s);
        if ($receivables != 0.0) {
            $post('Tilgodehavender (udestående fakturaer)', $receivables, '', $s);
        }
        if ($momsNet < 0) {
            $post('Tilgodehavende moms hos SKAT', -$momsNet, '', $s);
        }
        foreach ($assets as $a) {
            $post($a['label'], $a['amount'], $a['note'], $s);
        }
        $aSum = round($s[0], 2);
        $add('Aktiver', 'Aktiver i alt', $aSum);

        $l = 0.0;
        $liabRow = static function (string $label, float $v, string $note) use ($add, &$l): void {
            $add('Passiver', $label, $v, $note);
            $l += $v;
        };
        if ($momsNet > 0) {
            $liabRow('Skyldig moms', $momsNet, '');
        }
        if ($udlaeg != 0.0) {
            $liabRow('Udlæg skyldig til ejer', $udlaeg, '');
        }
        foreach ($liab as $x) {
            $liabRow($x['label'], $x['amount'], $x['note']);
        }
        $l      = round($l, 2);
        $equity = round($aSum - $l, 2);
        $add('Passiver', 'Gæld i alt', $l);
        $add('Passiver', 'Egenkapital', $equity, 'aktiver minus gæld');
        $add('Passiver', 'Passiver i alt', round($l + $equity, 2));

        return ['rows' => $rows, 'assets' => $aSum, 'liabilities' => $l, 'equity' => $equity, 'bank_source' => $bankSource, 'as_of' => $date];
    }

    private function sum(string $sql, int $userId, string $date, bool $twice = false): float
    {
        $p = [':u' => $userId, ':d' => $date];
        if ($twice) {
            $p[':d2'] = $date;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($p);

        return round((float) $stmt->fetchColumn(), 2);
    }
}
