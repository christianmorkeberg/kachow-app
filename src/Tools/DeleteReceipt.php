<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;
use App\Receipts\ReceiptStorage;

/**
 * Tool: delete an expense/receipt by id (and its image file, if any).
 */
final class DeleteReceipt implements Tool
{
    public function __construct(private Receipts $receipts, private ReceiptStorage $storage)
    {
    }

    public function name(): string
    {
        return 'delete_receipt';
    }

    public function description(): string
    {
        return 'Deletes an expense/receipt by its id (get it from get_expenses, whose results include '
            . 'each item id). Permanent; also removes the photo if there is one. If which expense is '
            . 'unclear, call get_expenses first to find the right id. Only delete one the user clearly '
            . 'identified.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['id' => ['type' => 'integer', 'description' => 'The receipt id.']],
            'required'   => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'A valid receipt id is required.'];
        }

        // Read it first so the "deleted" card can name what was removed.
        $row = $this->receipts->get($userId, $id);
        if ($row === null) {
            return ['deleted' => false, 'error' => 'No such expense (it may already be gone).'];
        }

        $fileRef = $this->receipts->delete($userId, $id);
        if ($fileRef !== null) {
            $this->storage->delete($userId, $fileRef);
        }

        $vendor   = trim((string) ($row['vendor'] ?? '')) ?: 'Expense';
        $currency = (string) ($row['currency'] ?? 'DKK');
        $detail   = $row['total'] !== null
            ? $vendor . ' · ' . number_format((float) $row['total'], 2) . ' ' . $currency
            : $vendor;

        return [
            'deleted' => true,
            // A distinct "deleted" card so the turn doesn't re-show the expense as if live.
            '_render' => ['kind' => 'notice', 'tone' => 'deleted', 'title' => 'Expense deleted', 'detail' => $detail],
        ];
    }
}
