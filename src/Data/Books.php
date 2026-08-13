<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Assembles the bookkeeping "cockpit" — a single modular overview payload composed
 * from the income, expense, draw and settings data. DK-only, DKK, 25% moms. The
 * range is resolved by the caller (tool/endpoint) and passed in, so this class has no
 * dependency on the Tools layer.
 *
 * The reserve is an ESTIMATE (net moms + a % of profit for income tax/AM-bidrag) — a
 * buffer so the owner doesn't overspend money owed to SKAT, never a filed figure.
 */
final class Books
{
    /** Period options for the cockpit switcher (key => label). */
    public const PERIODS = [
        ['key' => 'this_month',   'label' => 'Month'],
        ['key' => 'this_quarter', 'label' => 'Quarter'],
        ['key' => 'this_year',    'label' => 'Year'],
        ['key' => 'all',          'label' => 'All'],
    ];

    public function __construct(
        private Income $income,
        private Receipts $receipts,
        private OwnerDraws $draws,
        private UserSettings $settings,
    ) {
    }

    /**
     * The full cockpit card (kind: bookkeeping) for a resolved period.
     *
     * @return array<string, mixed>
     */
    public function overview(int $userId, ?string $from, ?string $to, string $label, string $periodKey): array
    {
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
            'kind'        => 'bookkeeping',
            'title'       => 'Books · ' . $label,
            'currency'    => 'DKK',
            'period_key'  => $periodKey,
            'period_label'=> $label,
            'periods'     => self::PERIODS,
            'kpis'        => [
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
