<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Cash;
use App\Data\CashEntries;

/**
 * Tool: record a manual bank movement that isn't already an invoice, expense or owner
 * draw — a moms payment to SKAT, a bank fee, or money the owner puts in. Keeps the
 * expected-balance view accurate. Re-renders the cash card.
 */
final class RecordCashMovement implements Tool
{
    public function __construct(private CashEntries $entries, private Cash $cash)
    {
    }

    public function name(): string
    {
        return 'record_cash_movement';
    }

    public function description(): string
    {
        return 'Records a bank-account movement NOT captured elsewhere: a moms payment to SKAT, an income-tax '
            . 'payment, a bank fee, or money the owner injects. Do NOT use this for invoices (add_income), '
            . 'expenses (add_expense) or paying yourself (add_owner_draw) — only for cash moves those tools '
            . 'miss. Examples: "I paid 3450 kr moms to SKAT", "bank fee of 50 kr", "I put 10000 into the '
            . 'business account", "jeg betalte 3450 i moms", "gebyr på 50 kr". category: moms (a moms payment/'
            . 'refund), tax (income tax), fee (bank/card fee), deposit (money you add), other.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'direction' => [
                    'type'        => 'string',
                    'enum'        => ['in', 'out'],
                    'description' => 'in = money INTO the account (a deposit, a refund from SKAT); out = money '
                        . 'OUT (a moms/tax payment, a fee). A moms payment to SKAT is "out".',
                ],
                'amount'    => ['type' => 'number', 'description' => 'Amount in DKK, positive.'],
                'category'  => [
                    'type' => 'string',
                    'enum' => ['moms', 'tax', 'fee', 'deposit', 'other'],
                    'description' => 'What kind of movement. Use "moms" for a moms payment/refund, "tax" for '
                        . 'income tax, "fee" for bank fees, "deposit" for money the owner adds.',
                ],
                'note'      => ['type' => 'string', 'description' => 'Optional short note, e.g. "Q1 2026 moms".'],
                'date'      => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['direction', 'amount'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $direction = ((string) ($arguments['direction'] ?? 'out')) === 'in' ? 'in' : 'out';
        $amount    = (float) ($arguments['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'An amount greater than zero is required.'];
        }
        $category = CashEntries::normalizeCategory($arguments['category'] ?? 'other');
        $note     = isset($arguments['note']) ? (string) $arguments['note'] : null;
        $date     = isset($arguments['date']) ? (string) $arguments['date'] : null;

        $this->entries->add($userId, $direction, $amount, $category, $note, $date);
        $card = $this->cash->position($userId);

        return [
            'recorded'      => true,
            'direction'     => $direction,
            'amount'        => round($amount, 2),
            'category'      => $category,
            'expected'      => $card['expected'],
            'free_to_spend' => $card['free_to_spend'],
            '_render'       => $card,
        ];
    }
}
