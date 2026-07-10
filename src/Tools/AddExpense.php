<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: record a business expense from a spoken/typed description (no photo) —
 * "add expense: 250 kr lunch at Café X today". Creates a draft the user confirms
 * on the card.
 */
final class AddExpense implements Tool
{
    public function __construct(private Receipts $receipts)
    {
    }

    public function name(): string
    {
        return 'add_expense';
    }

    public function description(): string
    {
        return 'Records a business expense the user describes in words (no receipt photo). Provide '
            . 'what they said: amount as total (incl. VAT), the vendor/what it was for, and if they '
            . 'gave them: the VAT/moms amount, the date, and a category. Amounts are DKK unless stated. '
            . 'It becomes a draft shown as a card for the user to confirm — so you do not need every '
            . 'field. Categories: ' . implode(', ', Receipts::CATEGORIES) . '.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'total'    => ['type' => 'number', 'description' => 'Amount paid, including VAT.'],
                'vendor'   => ['type' => 'string', 'description' => 'Vendor or what it was for (e.g. "Café X", "Parking").'],
                'vat'      => ['type' => 'number', 'description' => 'VAT / moms amount, if stated.'],
                'date'     => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD. Omit for today.'],
                'category' => ['type' => 'string', 'enum' => Receipts::CATEGORIES, 'description' => 'Best-fit category.'],
                'currency' => ['type' => 'string', 'description' => 'ISO currency, default DKK.'],
                'note'     => ['type' => 'string', 'description' => 'Optional short note.'],
            ],
            'required' => ['total'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!isset($arguments['total']) || !is_numeric($arguments['total'])) {
            return ['error' => 'How much was the expense?'];
        }

        $date = isset($arguments['date']) && trim((string) $arguments['date']) !== ''
            ? (string) $arguments['date']
            : (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');

        $id = $this->receipts->create($userId, [
            'total'        => $arguments['total'],
            'vendor'       => $arguments['vendor'] ?? null,
            'vat'          => $arguments['vat'] ?? null,
            'purchased_at' => $date,
            'category'     => $arguments['category'] ?? null,
            'currency'     => $arguments['currency'] ?? 'DKK',
            'note'         => $arguments['note'] ?? null,
        ], 'manual');

        $row = $this->receipts->get($userId, $id);

        return [
            'created' => true,
            'id'      => $id,
            'status'  => 'draft',
            '_render' => $row !== null ? $this->receipts->cardWithChecks($userId, $row) : null,
        ];
    }
}
