<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\Kr;
use DateTimeImmutable;
use PDO;

/**
 * The remaining statements of the loan pack, all Danish rows ready for export: resultatopgørelse, fakturaoversigt (per invoice and per customer),
 * moms pr. kvartal and a 12-month budget. DKK only; booked income and confirmed expenses only (drafts are not counted).
 */
final class LoanReports
{
    private PDO $db;

    /** Same display names as the ledger. */
    private const CATEGORY_DA = [
        'Groceries/Supplies' => 'Varer og materialer', 'Meals & Entertainment' => 'Måltider og repræsentation', 'Travel & Transport' => 'Rejser og transport',
        'Office & Equipment' => 'Kontor og udstyr', 'Software & Subscriptions' => 'Software og abonnementer', 'Fees & Bank' => 'Gebyrer og bank',
        'Marketing' => 'Markedsføring', 'Utilities & Phone' => 'El, internet og telefon', 'Other' => 'Øvrige udgifter',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Resultatopgørelse for a period (all amounts ex moms). $mileage = the business driving deduction for the period (no cash).
     *
     * @return list<array<string, string>>
     */
    public function profitLoss(int $userId, string $from, string $to, float $mileage = 0.0): array
    {
        $rev = $this->one('SELECT COALESCE(SUM(amount_ex_vat),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at BETWEEN :f AND :t', $userId, $from, $to);
        $stmt = $this->db->prepare('SELECT category, COALESCE(SUM(total - COALESCE(vat,0)),0) AS ex FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\'
            AND purchased_at BETWEEN :f AND :t GROUP BY category ORDER BY ex DESC');
        $stmt->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
        $rows = [['post' => 'Omsætning', 'beloeb' => Kr::amount($rev), 'sektion' => 'Indtægter']];
        $exp  = 0.0;
        $by   = [];
        foreach ($stmt->fetchAll() as $r) {
            $name = (string) ($r['category'] ?? '') !== '' ? (self::CATEGORY_DA[(string) $r['category']] ?? (string) $r['category']) : 'Øvrige udgifter';
            $by[$name] = ($by[$name] ?? 0.0) + (float) $r['ex'];
            $exp += (float) $r['ex'];
        }
        arsort($by);
        foreach ($by as $name => $v) {
            $rows[] = ['post' => $name, 'beloeb' => Kr::amount(-$v), 'sektion' => 'Udgifter'];
        }
        if ($mileage > 0) {
            $rows[] = ['post' => 'Kørselsfradrag (erhvervskørsel)', 'beloeb' => Kr::amount(-$mileage), 'sektion' => 'Udgifter'];
        }
        $rows[] = ['post' => 'Udgifter i alt', 'beloeb' => Kr::amount(-($exp + $mileage)), 'sektion' => 'Udgifter'];
        $rows[] = ['post' => 'Resultat før skat', 'beloeb' => Kr::amount($rev - $exp - $mileage), 'sektion' => 'Resultat'];

        return $rows;
    }

    /**
     * One row per booked invoice in the period.
     *
     * @return list<array<string, string>>
     */
    public function invoices(int $userId, string $from, string $to): array
    {
        $stmt = $this->db->prepare('SELECT doc_number, customer, issued_at, paid_at, amount_ex_vat, vat, total FROM income
            WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at BETWEEN :f AND :t ORDER BY issued_at, id');
        $stmt->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
        $today = new DateTimeImmutable('today');
        $out   = [];
        foreach ($stmt->fetchAll() as $r) {
            $paid = (string) ($r['paid_at'] ?? '') !== '';
            $days = $paid ? '' : (string) max(0, (int) $today->diff(new DateTimeImmutable((string) $r['issued_at']))->days);
            $out[] = ['faktura' => (string) ($r['doc_number'] ?? ''), 'kunde' => (string) ($r['customer'] ?? ''), 'dato' => Kr::date((string) $r['issued_at']),
                'ex_moms' => Kr::amount((float) $r['amount_ex_vat']), 'moms' => Kr::amount((float) $r['vat']), 'i_alt' => Kr::amount((float) $r['total']),
                'status' => $paid ? 'Betalt ' . Kr::date((string) $r['paid_at']) : 'Udestående', 'dage_siden_faktura' => $days];
        }

        return $out;
    }

    /**
     * Per customer: number of invoices, invoiced total, paid and outstanding (all incl. moms).
     *
     * @return list<array<string, string>>
     */
    public function customers(int $userId, string $from, string $to): array
    {
        $stmt = $this->db->prepare('SELECT customer, COUNT(*) AS n, COALESCE(SUM(total),0) AS tot,
                COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN total ELSE 0 END),0) AS paid
            FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at BETWEEN :f AND :t GROUP BY customer ORDER BY tot DESC');
        $stmt->execute([':u' => $userId, ':f' => $from, ':t' => $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['kunde' => (string) ($r['customer'] ?? '') !== '' ? (string) $r['customer'] : '(uden kunde)', 'antal' => (string) $r['n'],
                'faktureret' => Kr::amount((float) $r['tot']), 'betalt' => Kr::amount((float) $r['paid']), 'udestaaende' => Kr::amount((float) $r['tot'] - (float) $r['paid'])];
        }

        return $out;
    }

    /**
     * Moms pr. kvartal for a year: salgsmoms, købsmoms, tilsvar and what was paid to / refunded by SKAT in the quarter (manual 'moms' bank movements).
     *
     * @return list<array<string, string>>
     */
    public function momsQuarters(int $userId, int $year): array
    {
        $rows = [];
        $tot  = ['s' => 0.0, 'k' => 0.0, 'p' => 0.0];
        for ($q = 1; $q <= 4; $q++) {
            $f = sprintf('%d-%02d-01', $year, ($q - 1) * 3 + 1);
            $t = (new DateTimeImmutable($f))->modify('+2 months')->format('Y-m-t');
            $s = $this->one('SELECT COALESCE(SUM(vat),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at BETWEEN :f AND :t', $userId, $f, $t);
            $k = $this->one('SELECT COALESCE(SUM(vat),0) FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\' AND purchased_at BETWEEN :f AND :t', $userId, $f, $t);
            $p = $this->one('SELECT COALESCE(SUM(CASE WHEN direction=\'out\' THEN amount ELSE -amount END),0) FROM cash_entries WHERE user_id=:u AND category=\'moms\' AND occurred_at BETWEEN :f AND :t', $userId, $f, $t);
            $rows[] = ['kvartal' => 'Q' . $q . ' ' . $year, 'salgsmoms' => Kr::amount($s), 'koebsmoms' => Kr::amount($k), 'til_betaling' => Kr::amount($s - $k), 'betalt_til_skat' => Kr::amount($p)];
            $tot['s'] += $s;
            $tot['k'] += $k;
            $tot['p'] += $p;
        }
        $rows[] = ['kvartal' => 'I alt ' . $year, 'salgsmoms' => Kr::amount($tot['s']), 'koebsmoms' => Kr::amount($tot['k']), 'til_betaling' => Kr::amount($tot['s'] - $tot['k']), 'betalt_til_skat' => Kr::amount($tot['p'])];

        return $rows;
    }

    /**
     * A 12-month budget starting the month after $lastMonth (YYYY-MM-01): the monthly average of the 12 months up to and including it, moved by $growthPct,
     * unless income/expenses per month are given. Honest label: a projection from actuals, not a promise.
     *
     * @return array{rows:list<array<string,string>>, avg_income:float, avg_expenses:float}
     */
    public function budget(int $userId, string $lastMonth, float $growthPct = 0.0, ?float $income = null, ?float $expenses = null): array
    {
        $end   = (new DateTimeImmutable($lastMonth))->modify('last day of this month');
        $start = $end->modify('-11 months')->modify('first day of this month');
        $inc   = $this->one('SELECT COALESCE(SUM(amount_ex_vat),0) FROM income WHERE user_id=:u AND status=\'booked\' AND currency=\'DKK\' AND issued_at BETWEEN :f AND :t', $userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $exp   = $this->one('SELECT COALESCE(SUM(total - COALESCE(vat,0)),0) FROM receipts WHERE user_id=:u AND status=\'confirmed\' AND currency=\'DKK\' AND purchased_at BETWEEN :f AND :t', $userId, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $k     = 1 + $growthPct / 100;
        $mi    = $income ?? round($inc / 12 * $k, 2);
        $me    = $expenses ?? round($exp / 12 * $k, 2);
        $names = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
        $rows  = [];
        for ($i = 1; $i <= 12; $i++) {
            $m = $end->modify('first day of this month')->modify("+{$i} months");
            $rows[] = ['maaned' => $names[(int) $m->format('n') - 1] . ' ' . $m->format('Y'), 'indtaegter' => Kr::amount($mi), 'udgifter' => Kr::amount(-$me), 'resultat' => Kr::amount($mi - $me)];
        }
        $rows[] = ['maaned' => 'I alt', 'indtaegter' => Kr::amount($mi * 12), 'udgifter' => Kr::amount(-$me * 12), 'resultat' => Kr::amount(($mi - $me) * 12)];

        return ['rows' => $rows, 'avg_income' => $mi, 'avg_expenses' => $me];
    }

    private function one(string $sql, int $userId, string $from, string $to): float
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u' => $userId, ':f' => $from, ':t' => $to]);

        return round((float) $stmt->fetchColumn(), 2);
    }
}
