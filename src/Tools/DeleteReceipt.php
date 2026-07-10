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
        return 'Deletes an expense/receipt by its id (get it from get_receipts). Permanent; also '
            . 'removes the photo if there is one. Only delete one the user clearly identified.';
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

        $fileRef = $this->receipts->delete($userId, $id);
        if ($fileRef === null && $this->receipts->get($userId, $id) === null) {
            // Either deleted (image-less) or never existed — treat idempotently.
            return ['deleted' => true];
        }
        if ($fileRef !== null) {
            $this->storage->delete($userId, $fileRef);
        }

        return ['deleted' => true];
    }
}
