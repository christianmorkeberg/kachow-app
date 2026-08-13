<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Assembles the bookkeeping "cockpit" — a single modular overview payload composed
 * from the income, expense, draw and settings data. DK-only, DKK, 25% moms.
 *
 * The period is a GRANULARITY (month/quarter/year/all) + an OFFSET (0 = current,
 * -1 = previous, …) so the cockpit can page back through periods. The reserve is an
 * ESTIMATE (net moms + a % of profit for income tax/AM-bidrag) — a buffer so the owner
 * doesn't overspend money owed to SKAT, never a filed figure.
 */
final class Books
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    /** Granularity options for the cockpit switcher (key => label). */
    public const GRANULARITIES = [
        ['key' => 'month',   'label' => 'Month'],
        ['key' => 'quarter', 'label' => 'Quarter'],
        ['key' => 'year',    'label' => 'Year'],
        ['key' => 'all',     'label' => 'All'],
    ];

    /**
     * Resolves a granularity + offset into an inclusive [from, to, label] window.
     * offset 0 = current period, -1 = the previous one, +1 = the next, etc.
     *
     * @return array{0:?string, 1:?string, 2:string}
     */
    public static function range(string $gran, int $offset): array
    {
        $tz  = new DateTimeZone(self::LOCAL_TZ);
        $now = new DateTimeImmutable('now', $tz);

        if ($gran === 'all') {
            return [null, null, 'All time'];
        }
        if ($gran === 'year') {
            $y = (int) $now->format('Y') + $offset;
            return [$y . '-01-01', $y . '-12-31', (string) $y];
        }
        if ($gran === 'month') {
            $start = $now->modify('first day of this month')->setTime(0, 0)->modify($offset . ' months');
            return [$start->format('Y-m-d'), $start->format('Y-m-t'), $start->format('F Y')];
        }

        // quarter
        $q        = intdiv((int) $now->format('n') - 1, 3);                         // 0..3 (current)
        $curStart = $now->setDate((int) $now->format('Y'), $q * 3 + 1, 1)->setTime(0, 0);
        $start    = $curStart->modify(($offset * 3) . ' months');
        $end      = $start->modify('+2 months')->modify('last day of this month');
        $qn       = intdiv((int) $start->format('n') - 1, 3) + 1;
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), 'Q' . $qn . ' ' . $start->format('Y')];
    }

    public function __construct(
        private Income $income,
        private Receipts $receipts,
        private OwnerDraws $draws,
        private UserSettings $settings,
    ) {
    }

    /**
     * The full cockpit card (kind: bookkeeping) for a granularity + offset.
     *
     * @return array<string, mixed>
     */
    public function overview(int $userId, string $gran, int $offset = 0): array
    {
        [$from, $to, $label] = self::range($gran, $offset);
        $inc = $this->income->periodTotals($userId, $from, $to);      // booked, DKK: ex/vat/total/count
        $exp = $this->receipts->periodTotals($userId, $from, $to);    // confirmed, DKK: total/vat/ex/count

        $outputVat = $inc['vat'];
        $inputVat  = $exp['vat'];
        $netMoms   = round($outputVat - $inputVat, 2);
        $profitEx  = round($inc['ex'] - $exp['ex'], 2);

        $pct         = $this->settings->reservePct($userId);
        $taxReserve  = round(max(0.0, $profitEx) * $pct / 100, 2);
        $reserveTot  = round(max(0.0, $netMoms) + $taxReserve, 2);

        // Outstanding debtors (unpaid booked invoices in the period).
        $unpaid = $this->income->items($userId, $from, $to, 'unpaid', 300);
        $outstandingTotal = 0.0;
        foreach ($unpaid as $u) {
            $outstandingTotal += (float) $u['total'];
        }
        $outstandingTotal = round($outstandingTotal, 2);

        $udlaeg = $this->receipts->outstandingUdlaeg($userId);
        $udlaegTotal = 0.0;
        foreach ($udlaeg['totals'] as $t) {
            if ($t['currency'] === 'DKK') {
                $udlaegTotal = $t['total'];
            }
        }

        $expSummary = $this->receipts->summary($userId, $from, $to);
        $drawSummary = $this->draws->summary($userId, $from, $to);
        $drawTotalDkk = 0.0; $drawCount = 0;
        foreach ($drawSummary['totals'] as $t) {
            if ($t['currency'] === 'DKK') { $drawTotalDkk = $t['total']; $drawCount = $t['count']; }
        }

        return [
            'kind'          => 'bookkeeping',
            'title'         => 'Books · ' . $label,
            'currency'      => 'DKK',
            'granularity'   => $gran,
            'offset'        => $offset,
            'period_label'  => $label,
            'granularities' => self::GRANULARITIES,
            'can_next'      => $gran !== 'all' && $offset < 0,   // no navigating into the future
            'kpis'          => [
                'revenue'     => $inc['ex'],
                'output_vat'  => $outputVat,
                'input_vat'   => $inputVat,
                'net_moms'    => $netMoms,
                'expenses_ex' => $exp['ex'],
                'expenses'    => $exp['total'],
                'profit'      => $profitEx,
                'outstanding' => $outstandingTotal,
                'reserve'     => [
                    'moms'   => round(max(0.0, $netMoms), 2),
                    'tax'    => $taxReserve,
                    'total'  => $reserveTot,
                    'pct'    => $pct,
                    'profit' => max(0.0, $profitEx),
                ],
            ],
            'income'   => [
                'counts' => $this->income->statusCounts($userId, $from, $to),
                'items'  => $this->income->items($userId, $from, $to, null, 40),
            ],
            'expenses' => [
                'total'       => $exp['total'],
                'vat'         => $exp['vat'],
                'ex'          => $exp['ex'],
                'count'       => $exp['count'],
                'udlaeg_owed' => ['total' => $udlaegTotal, 'count' => $udlaeg['count']],
                'items'       => array_map(static fn (array $i): array => [
                    'id'       => $i['id'],
                    'vendor'   => $i['vendor'],
                    'date'     => $i['date'],
                    'total'    => $i['total'],
                    'currency' => $i['currency'],
                    'category' => $i['category'],
                ], array_slice($expSummary['items'], 0, 20)),
            ],
            'draws'    => [
                'total' => $drawTotalDkk,
                'count' => $drawCount,
                'items' => array_slice($drawSummary['items'], 0, 20),
            ],
        ];
    }

    /**
     * The detail card for one income entry (for the cockpit drill-in). Returns the
     * standard income card, or null if not found / not the user's.
     *
     * @return array<string, mixed>|null
     */
    public function incomeEntry(int $userId, int $id): ?array
    {
        $row = $this->income->get($userId, $id);

        return $row !== null ? $this->income->card($row) : null;
    }

    /**
     * The detail card for one expense (receipt) — for the cockpit expense drill-in.
     * Returns the standard receipt card (with duplicate check), or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function expenseEntry(int $userId, int $id): ?array
    {
        $row = $this->receipts->get($userId, $id);

        return $row !== null ? $this->receipts->cardWithChecks($userId, $row) : null;
    }
}
