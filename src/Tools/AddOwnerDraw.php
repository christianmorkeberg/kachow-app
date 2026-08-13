<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\OwnerDraws;

/**
 * Tool: record an owner drawing (privat hævning) — money the user paid themselves out
 * of the business. For an enkeltmandsvirksomhed this is NOT a salary/expense and must
 * NOT be booked as one; it's a private withdrawal that doesn't affect profit or moms.
 */
final class AddOwnerDraw implements Tool
{
    public function __construct(
        private OwnerDraws $draws,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'add_owner_draw';
    }

    public function description(): string
    {
        return 'Records an owner DRAWING (privat hævning) — money the user paid THEMSELVES from the '
            . 'business ("I paid myself 15000", "jeg hævede 10000 til mig selv / min løn"). For an '
            . 'enkeltmandsvirksomhed this is a private withdrawal, NOT a deductible salary or expense — it '
            . 'does not reduce profit or moms, so never record owner pay via add_expense. Use this only for '
            . 'the owner paying themselves, not for real business costs. Amounts are DKK unless stated.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'amount'   => ['type' => 'number', 'description' => 'Amount the user paid themselves.'],
                'date'     => ['type' => 'string', 'description' => 'Date YYYY-MM-DD. Omit for today.'],
                'currency' => ['type' => 'string', 'description' => 'ISO currency, default DKK.'],
                'note'     => ['type' => 'string', 'description' => 'Optional short note.'],
            ],
            'required' => ['amount'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!isset($arguments['amount']) || !is_numeric($arguments['amount'])) {
            return ['error' => 'How much did you pay yourself?'];
        }
        $amount = (float) $arguments['amount'];
        if ($amount <= 0) {
            return ['error' => 'The amount should be greater than zero.'];
        }

        $date = isset($arguments['date']) && trim((string) $arguments['date']) !== ''
            ? (string) $arguments['date'] : OwnerDraws::today();
        $id = $this->draws->add(
            $userId,
            $amount,
            $date,
            (string) ($arguments['currency'] ?? 'DKK'),
            isset($arguments['note']) ? (string) $arguments['note'] : null
        );
        $this->audit->log($userId, 'draw', $id, 'create', ['amount' => round($amount, 2), 'date' => $date]);

        // Show the month's draws so the user can see the running total.
        $from = date('Y-m-01', strtotime($date) ?: time());
        $to   = date('Y-m-t', strtotime($date) ?: time());
        $title = 'Owner draws · ' . date('F Y', strtotime($date) ?: time());

        return [
            'recorded' => true,
            'id'       => $id,
            'note'     => 'Recorded as a private drawing (hævning) — not an expense, so it does not affect profit or moms.',
            '_render'  => $this->draws->card($userId, $from, $to, $title),
        ];
    }
}
