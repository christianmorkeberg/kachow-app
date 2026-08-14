<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Resultatopgørelse (profit & loss) for a period: revenue − expenses = profit, all
 * EX-VAT (moms is neither income nor cost — it passes through to SKAT). Expenses are
 * broken down by category. Plus the income-tax reserve estimate on the profit.
 *
 * Accrual basis (by invoice/expense date, booked/confirmed), like the moms view — this
 * is the "how did the business do" statement, distinct from the cash/liquidity view.
 * Uses the same granularity + offset period model as the cockpit (Books::range).
 */
final class ProfitLoss
{
    public function __construct(
        private Income $income,
        private Receipts $receipts,
        private UserSettings $settings,
    ) {
    }

    /**
     * The P&L card (kind: pl) for a granularity + offset.
     *
     * @return array<string, mixed>
     */
    public function statement(int $userId, string $gran, int $offset = 0): array
    {
        [$from, $to, $label] = Books::range($gran, $offset);

        $inc        = $this->income->periodTotals($userId, $from, $to);   // booked DKK: ex/vat/total/count
        $categories = $this->receipts->categoryTotals($userId, $from, $to);

        $revenue    = $inc['ex'];
        $expensesEx = 0.0;
        foreach ($categories as $c) {
            $expensesEx += $c['ex'];
        }
        $expensesEx = round($expensesEx, 2);
        $profit     = round($revenue - $expensesEx, 2);

        $pct        = $this->settings->reservePct($userId);
        $taxReserve = round(max(0.0, $profit) * $pct / 100, 2);

        return [
            'kind'          => 'pl',
            'title'         => 'P&L · ' . $label,
            'currency'      => 'DKK',
            'granularity'   => $gran,
            'offset'        => $offset,
            'period_label'  => $label,
            'granularities' => Books::GRANULARITIES,
            'can_next'      => $gran !== 'all' && $offset < 0,
            'revenue'       => $revenue,
            'income_count'  => $inc['count'],
            'expenses'      => $expensesEx,
            'expense_categories' => $categories,
            'profit'        => $profit,
            'tax_reserve'   => ['amount' => $taxReserve, 'pct' => $pct],
        ];
    }
}
