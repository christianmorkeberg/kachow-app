<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\Income;

/**
 * Tool: mark a recorded invoice / income entry as paid (records the payment date, so
 * outstanding-invoice tracking stays correct). Does not change the moms period, which
 * follows the invoice date, not the payment date.
 */
final class MarkInvoicePaid implements Tool
{
    public function __construct(
        private Income $income,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'mark_invoice_paid';
    }

    public function description(): string
    {
        return 'Marks a recorded invoice / income entry as PAID, recording when the money arrived — for '
            . '"the kommune invoice got paid", "faktura 12 er betalt". Needs the income entry id: if you do '
            . 'not already have it from a recent income card or get_income result, call get_income first and '
            . 'use the matching id — do NOT guess an id. This only records payment; it does not affect the '
            . 'moms period (which follows the invoice date).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'        => ['type' => 'integer', 'description' => 'The income entry id (from a card or get_income).'],
                'paid_date' => ['type' => 'string', 'description' => 'Date paid YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Which invoice? I need its id — check get_income.'];
        }
        if ($this->income->get($userId, $id) === null) {
            return ['error' => 'I couldn\'t find that income entry.'];
        }

        $date = isset($arguments['paid_date']) && trim((string) $arguments['paid_date']) !== ''
            ? (string) $arguments['paid_date'] : null;
        $this->income->markPaid($userId, $id, $date);
        $this->audit->log($userId, 'income', $id, 'paid', ['paid_at' => $date ?? Income::today()]);

        $row = $this->income->get($userId, $id);

        return [
            'ok'      => true,
            'id'      => $id,
            'paid_at' => $row !== null && $row['paid_at'] !== null ? (string) $row['paid_at'] : null,
            '_render' => $row !== null ? $this->income->card($row) : null,
        ];
    }
}
