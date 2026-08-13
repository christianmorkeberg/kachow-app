<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Cash position / liquidity — "how much SHOULD be in my bank account", and of that how
 * much is actually free to spend once the moms + income-tax you owe is set aside.
 *
 * This is a CASH view (when money actually moved), distinct from the accrual moms/P&L
 * views (when an invoice was issued). Expected balance =
 *
 *   opening balance
 *   + invoices PAID (gross received)
 *   + manual money-in (deposits, refunds…)
 *   − expenses the business paid (direct + reimbursed udlæg)
 *   − owner draws
 *   − manual money-out (moms payments, bank fees…)
 *
 * Free to spend = expected balance − (moms still owed + income-tax reserve). The VAT you
 * collected sits in the account until you file, so it's real cash but not really yours.
 * moms owed = cumulative (salgs − købs) − moms already paid to SKAT (a 'moms' cash-out);
 * tax reserve = pct% of cumulative profit − income tax already paid (a 'tax' cash-out).
 *
 * DK-only, DKK. Owner-scoped through the composed data classes.
 */
final class Cash
{
    public function __construct(
        private Income $income,
        private Receipts $receipts,
        private OwnerDraws $draws,
        private CashEntries $entries,
        private UserSettings $settings,
    ) {
    }

    /**
     * The full cash position for a user.
     *
     * @return array<string, mixed>
     */
    public function position(int $userId): array
    {
        $opening     = $this->settings->openingBalance($userId);
        $received    = $this->income->receivedTotal($userId);        // invoices paid (gross in)
        $expensesOut = $this->receipts->cashPaidTotal($userId);      // business-paid expenses (out)
        $drawsOut    = $this->draws->totalDkk($userId);              // owner draws (out)
        $manual      = $this->entries->grossTotals($userId);         // manual in / out

        $moneyIn  = round($received + $manual['in'], 2);
        $moneyOut = round($expensesOut + $drawsOut + $manual['out'], 2);
        $expected = round($opening + $moneyIn - $moneyOut, 2);

        // Reserve (what's owed but still in the account): cumulative moms + income tax,
        // net of what has already been paid to SKAT via 'moms'/'tax' cash movements.
        $allInc = $this->income->periodTotals($userId, null, null);   // ex / vat / total / count (booked DKK)
        $allExp = $this->receipts->periodTotals($userId, null, null); // total / vat / ex / count (confirmed DKK)

        // Signed running moms position after payments: + = you still owe SKAT, − = SKAT
        // owes you a refund (købsmoms exceeded salgsmoms, or you overpaid).
        $momsNet    = round(($allInc['vat'] - $allExp['vat']) + $this->entries->net($userId, 'moms'), 2);
        $momsOwed   = round(max(0.0, $momsNet), 2);   // set aside only when you owe
        $refundDue  = round(max(0.0, -$momsNet), 2);  // expected inflow, not yet in the account

        $pct        = $this->settings->reservePct($userId);
        $cumProfit  = round($allInc['ex'] - $allExp['ex'], 2);
        $taxGross   = round(max(0.0, $cumProfit) * $pct / 100, 2);
        $taxReserve = round(max(0.0, $taxGross + $this->entries->net($userId, 'tax')), 2);

        $reserveTotal = round($momsOwed + $taxReserve, 2);
        $free         = round($expected - $reserveTotal, 2);

        return [
            'kind'          => 'cash',
            'title'         => 'Cash',
            'currency'      => 'DKK',
            'expected'      => $expected,
            'free_to_spend' => $free,
            // A moms refund SKAT owes you but hasn't paid yet (0 when you owe instead).
            // Not in expected/free-to-spend until received; shown so it isn't forgotten.
            'refund_expected'  => $refundDue,
            'free_incl_refund' => round($free + $refundDue, 2),
            'opening'       => $opening,
            'money_in'      => ['total' => $moneyIn, 'invoices_paid' => $received, 'other' => $manual['in']],
            'money_out'     => ['total' => $moneyOut, 'expenses' => $expensesOut, 'draws' => $drawsOut, 'other' => $manual['out']],
            'reserve'       => ['total' => $reserveTotal, 'moms' => $momsOwed, 'tax' => $taxReserve, 'pct' => $pct],
            'categories'    => CashEntries::CATEGORIES,
            'movements'     => $this->entries->recent($userId, 40),
        ];
    }
}
