<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BalanceItems;

/** Tool: enter a balance-sheet item Kachow cannot derive (equipment, a loan, a stated bank balance). */
final class SetBalanceItem implements Tool
{
    public function __construct(private BalanceItems $items)
    {
    }

    public function name(): string
    {
        return 'set_balance_item';
    }

    public function description(): string
    {
        return 'Records a BALANCE-SHEET item the books cannot derive: an asset (udstyr/equipment, a car, deposits) or a liability (lån, leverandørgæld), valid from a date '
            . 'until replaced. A stated bank balance from the bank statement is an asset with label "Bank". Setting the same label with amount 0 retires it. '
            . 'Examples: "my laptop is worth 9000", "I have a loan of 50000", "bank balance was 12345 on 31 Dec", "jeg har et lån på 50.000". Ask for the amount and date '
            . 'if missing; the date defaults to today.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind'   => ['type' => 'string', 'enum' => ['asset', 'liability'], 'description' => 'asset = something the business owns; liability = something it owes.'],
                'label'  => ['type' => 'string', 'description' => 'Short name, e.g. "Udstyr", "Lån", "Bank".'],
                'amount' => ['type' => 'number', 'description' => 'DKK, positive. 0 retires the item.'],
                'as_of'  => ['type' => 'string', 'description' => 'Valid from, YYYY-MM-DD. Default today.'],
                'note'   => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => ['kind', 'label', 'amount'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $label = trim((string) ($arguments['label'] ?? ''));
        if ($label === '' || !is_numeric($arguments['amount'] ?? null) || (float) $arguments['amount'] < 0) {
            return ['error' => 'label and a positive amount are required'];
        }
        $asOf = (string) ($arguments['as_of'] ?? '');
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) === 1 ? $asOf : date('Y-m-d');
        $this->items->set($userId, (string) ($arguments['kind'] ?? 'asset'), $label, (float) $arguments['amount'], $asOf, isset($arguments['note']) ? (string) $arguments['note'] : null);

        return ['saved' => true, 'kind' => $arguments['kind'] ?? 'asset', 'label' => $label, 'amount' => (float) $arguments['amount'], 'as_of' => $asOf];
    }
}
