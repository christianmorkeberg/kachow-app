<?php

declare(strict_types=1);

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Quarterly moms (Danish VAT) settlement — the momsafregning view.
 *
 * A moms-registered enkeltmandsvirksomhed on quarterly settlement owes, per quarter:
 *   salgsmoms (output VAT on issued invoices)  −  købsmoms (input VAT on expenses)
 *   = tilsvar (positive → pay SKAT, negative → money back).
 *
 * faktureringsprincippet: output VAT falls in the quarter of the invoice/delivery
 * date (issued_at), input VAT in the quarter of the expense date (purchased_at).
 *
 * DK quarterly deadline = the 1st of the 3rd month after the period ends
 * (Q1→1 Jun, Q2→1 Sep, Q3→1 Dec, Q4→1 Mar next year) = the quarter's first day + 5
 * months. SKAT shifts a deadline that lands on a weekend/holiday to the next banking
 * day; we show the nominal date and say so, rather than trying to model the calendar.
 *
 * These figures mirror the cockpit (booked/confirmed DKK only) — Kachow is a
 * bookkeeping ASSISTANT, the tilsvar shown here is what you'd file, not a filed figure.
 */
final class Moms
{
    public const LOCAL_TZ = 'Europe/Copenhagen';

    public function __construct(
        private Income $income,
        private Receipts $receipts,
    ) {
    }

    /**
     * The calendar quarter at an offset from the current one (0 = current, -1 = the
     * previous quarter, …). Returns [year, quarter(1..4)].
     *
     * @return array{0:int, 1:int}
     */
    public static function quarterAt(int $offset): array
    {
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $now   = new DateTimeImmutable('now', $tz);
        $q0    = intdiv((int) $now->format('n') - 1, 3);                 // 0..3 current
        $start = $now->setDate((int) $now->format('Y'), $q0 * 3 + 1, 1)->setTime(0, 0)
            ->modify(($offset * 3) . ' months');

        return [(int) $start->format('Y'), intdiv((int) $start->format('n') - 1, 3) + 1];
    }

    /**
     * Inclusive [from, to] 'Y-m-d' window for a calendar quarter.
     *
     * @return array{0:string, 1:string}
     */
    public static function range(int $year, int $q): array
    {
        $tz    = new DateTimeZone(self::LOCAL_TZ);
        $start = (new DateTimeImmutable('now', $tz))->setDate($year, ($q - 1) * 3 + 1, 1)->setTime(0, 0);
        $end   = $start->modify('+2 months')->modify('last day of this month');

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** The (nominal) filing deadline for a quarter: the quarter's first day + 5 months. */
    public static function deadline(int $year, int $q): DateTimeImmutable
    {
        $tz = new DateTimeZone(self::LOCAL_TZ);

        return (new DateTimeImmutable('now', $tz))->setDate($year, ($q - 1) * 3 + 1, 1)
            ->setTime(0, 0)->modify('+5 months');
    }

    /**
     * The full settlement for the quarter at $offset. All money is DKK; salgs/købs are
     * booked income VAT / confirmed expense VAT (udlæg included — still deductible).
     *
     * @return array<string, mixed>
     */
    public function settlement(int $userId, int $offset = 0): array
    {
        [$year, $q]   = self::quarterAt($offset);
        [$from, $to]  = self::range($year, $q);

        $inc = $this->income->periodTotals($userId, $from, $to);    // ex/vat/total/count (booked DKK)
        $exp = $this->receipts->periodTotals($userId, $from, $to);  // total/vat/ex/count (confirmed DKK)

        $salgsmoms = $inc['vat'];
        $kobsmoms  = $exp['vat'];
        $tilsvar   = round($salgsmoms - $kobsmoms, 2);

        $tz       = new DateTimeZone(self::LOCAL_TZ);
        $today    = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $deadline = self::deadline($year, $q);
        $daysLeft = (int) $today->diff($deadline)->format('%r%a');   // negative once overdue

        $draftIncome = $this->income->statusCounts($userId, $from, $to)['draft'];
        $draftExp    = $this->receipts->draftCount($userId, $from, $to);

        return [
            'year'          => $year,
            'quarter'       => $q,
            'label'         => 'Q' . $q . ' ' . $year,
            'from'          => $from,
            'to'            => $to,
            'salgsmoms'     => $salgsmoms,
            'kobsmoms'      => $kobsmoms,
            'tilsvar'       => $tilsvar,
            'pay'           => $tilsvar >= 0,                        // true = owe SKAT, false = refund
            'sales_count'   => $inc['count'],
            'expense_count' => $exp['count'],
            'deadline'      => $deadline->format('Y-m-d'),
            'days_left'     => $daysLeft,
            'period_open'   => $to >= $today->format('Y-m-d'),       // quarter not finished yet
            'draft_income'  => $draftIncome,
            'draft_expense' => $draftExp,
            'has_activity'  => ($inc['count'] + $exp['count']) > 0,
        ];
    }

    /**
     * The renderable moms card (kind: moms) for the quarter at $offset.
     *
     * @return array<string, mixed>
     */
    public function card(int $userId, int $offset = 0): array
    {
        $s = $this->settlement($userId, $offset);

        return [
            'kind'        => 'moms',
            'title'       => 'Moms · ' . $s['label'],
            'currency'    => 'DKK',
            'offset'      => $offset,
            'can_next'    => $offset < 0,                            // never page into the future
            'period_label' => $s['label'],
        ] + $s;
    }

    /**
     * For the deadline nudge: is a just-ended quarter's filing deadline within the next
     * $leadDays (and not yet past), with actual activity to file? Returns the settlement
     * of that quarter, or null if nothing is due. Looks at the previous quarter — the one
     * currently awaiting filing.
     *
     * @return array<string, mixed>|null
     */
    public function dueForNudge(int $userId, int $leadDays = 10): ?array
    {
        $s = $this->settlement($userId, -1);
        if (!$s['has_activity']) {
            return null;                                            // nothing to file → don't nag
        }
        if ($s['days_left'] < 0 || $s['days_left'] > $leadDays) {
            return null;                                            // not in the pre-deadline window
        }

        return $s;
    }
}
