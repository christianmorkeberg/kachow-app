<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LoanReports;

/** Tool: fakturaoversigt — every booked invoice in a period, or totals per customer. */
final class GetInvoiceOverview implements Tool
{
    public function __construct(private LoanReports $reports)
    {
    }

    public function name(): string
    {
        return 'get_invoice_overview';
    }

    public function description(): string
    {
        return 'FAKTURAOVERSIGT / order overview: every booked invoice in a period (number, customer, date, ex-moms, moms, total, paid or outstanding, days since '
            . 'invoice) or, with per_customer true, totals PER CUSTOMER (count, invoiced, paid, outstanding). Use for "fakturaoversigt", "hvad har jeg faktureret hver kunde", '
            . '"debitorer", "who owes me", "order list" for a bank. Danish columns. For a file call export_data with source get_invoice_overview (language da).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => BookkeepingRange::PARAMS + ['per_customer' => ['type' => 'boolean', 'description' => 'true = one row per customer instead of per invoice.']], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = BookkeepingRange::resolve($arguments);
        $from ??= '2000-01-01';
        $to   ??= '2100-12-31';
        if (!empty($arguments['per_customer'])) {
            return ['periode' => $label, 'kunder' => $this->reports->customers($userId, $from, $to)];
        }
        $rows = $this->reports->invoices($userId, $from, $to);

        return ['periode' => $label, 'antal' => count($rows), 'fakturaer' => $rows];
    }
}
