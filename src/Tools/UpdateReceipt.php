<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;

/**
 * Tool: correct a field on a receipt/expense by id (e.g. "change the total to
 * 250", "category is Meals"). Ids come from add_expense or get_receipts.
 */
final class UpdateReceipt implements Tool
{
    public function __construct(private Receipts $receipts)
    {
    }

    public function name(): string
    {
        return 'update_receipt';
    }

    public function description(): string
    {
        return 'Updates one or more fields of an existing expense/receipt by id (for corrections). '
            . 'Also use to confirm it by setting confirm=true. Get the id from add_expense or '
            . 'get_expenses. Categories: ' . implode(', ', Receipts::CATEGORIES) . '.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'       => ['type' => 'integer', 'description' => 'The receipt id.'],
                'vendor'   => ['type' => 'string'],
                'total'    => ['type' => 'number'],
                'vat'      => ['type' => 'number'],
                'date'     => ['type' => 'string', 'description' => 'Local date YYYY-MM-DD.'],
                'category' => ['type' => 'string', 'enum' => Receipts::CATEGORIES],
                'currency' => ['type' => 'string'],
                'note'     => ['type' => 'string'],
                'confirm'  => ['type' => 'boolean', 'description' => 'Set true to mark the expense confirmed.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0 || $this->receipts->get($userId, $id) === null) {
            return ['error' => 'I could not find that expense.'];
        }

        $fields = [];
        foreach (['vendor', 'total', 'vat', 'category', 'currency', 'note'] as $f) {
            if (array_key_exists($f, $arguments)) {
                $fields[$f] = $arguments[$f];
            }
        }
        if (array_key_exists('date', $arguments)) {
            $fields['purchased_at'] = $arguments['date'];
        }
        if ($fields !== []) {
            $this->receipts->update($userId, $id, $fields);
        }
        if (!empty($arguments['confirm'])) {
            $this->receipts->confirm($userId, $id);
        }

        $row = $this->receipts->get($userId, $id);

        return [
            'updated' => true,
            'status'  => $row !== null ? (string) $row['status'] : 'draft',
            '_render' => $row !== null ? $this->receipts->card($row) : null,
        ];
    }
}
