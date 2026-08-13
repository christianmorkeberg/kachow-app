<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\Receipts;

/**
 * Tool: mark a privately-paid expense (udlæg) as reimbursed — the company has paid the
 * owner back. The expense itself stays a normal deductible business cost; this only
 * closes the outlay so "udlæg owed to you" stops counting it.
 */
final class MarkExpenseReimbursed implements Tool
{
    public function __construct(
        private Receipts $receipts,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'mark_expense_reimbursed';
    }

    public function description(): string
    {
        return 'Marks a privately-paid expense (udlæg) as REIMBURSED — the business has paid the owner back '
            . 'for an outlay they covered from private money ("I paid myself back for the parking receipt", '
            . '"udlægget er refunderet"). Also flags it as privately paid if it wasn\'t already. Needs the '
            . 'receipt id: if you don\'t have it from a recent card or get_expenses, look it up first — do not '
            . 'guess. The expense stays a deductible business cost; this only records the reimbursement.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'   => ['type' => 'integer', 'description' => 'The receipt/expense id.'],
                'date' => ['type' => 'string', 'description' => 'Date reimbursed YYYY-MM-DD. Omit for today.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'Which expense? I need its id — check get_expenses.'];
        }
        $row = $this->receipts->get($userId, $id);
        if ($row === null) {
            return ['error' => 'I couldn\'t find that expense.'];
        }

        $date = isset($arguments['date']) && trim((string) $arguments['date']) !== ''
            ? (string) $arguments['date'] : null;
        $this->receipts->markReimbursed($userId, $id, $date);
        $this->audit->log($userId, 'expense', $id, 'reimburse', ['reimbursed_at' => $date ?? date('Y-m-d')]);

        $fresh = $this->receipts->get($userId, $id);

        return [
            'ok'            => true,
            'id'            => $id,
            'reimbursed_at' => $fresh !== null && $fresh['reimbursed_at'] !== null ? (string) $fresh['reimbursed_at'] : null,
            '_render'       => $fresh !== null ? $this->receipts->cardWithChecks($userId, $fresh) : null,
        ];
    }
}
