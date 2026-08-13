<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\Income;

/**
 * Tool: record business income — an issued invoice (public via NemHandel or a private
 * client) or other revenue. Creates a draft shown as a card the user confirms/books.
 * The counterpart to add_expense on the income side.
 */
final class AddIncome implements Tool
{
    public function __construct(
        private Income $income,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'add_income';
    }

    public function description(): string
    {
        return 'Records business INCOME the user reports: an issued invoice (a faktura — to a public '
            . 'body via NemHandel, or to a private client) or other revenue. Use for "I invoiced X for '
            . '5000 plus moms", "jeg har sendt en faktura til kommunen på 10000", "fik 2000 ind for et salg". '
            . 'Danish moms is 25%. Provide whichever amounts the user gave: amount_ex_vat (net, before moms) '
            . 'AND/OR total (incl. moms) AND/OR vat — the tool derives the rest (on 25%, moms = total ÷ 5). '
            . 'Set vatable=false only for genuinely VAT-free/zero-rated income. customer is who was invoiced; '
            . 'source is "nemhandel" for public-body invoices sent via NemHandel, "private" for a private '
            . 'client, else "manual". doc_number is the invoice number if the user has one (do NOT invent one). '
            . 'It becomes a draft card the user confirms — you need not fill every field. Amounts are DKK '
            . 'unless stated. This is INCOME, never an expense (that is add_expense). Categories: '
            . implode(', ', Income::CATEGORIES) . '.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'total'         => ['type' => 'number', 'description' => 'Gross amount incl. moms, if given.'],
                'amount_ex_vat' => ['type' => 'number', 'description' => 'Net amount excl. moms, if given.'],
                'vat'           => ['type' => 'number', 'description' => 'Moms (output VAT) amount, if stated.'],
                'vatable'       => ['type' => 'boolean', 'description' => 'False only for VAT-free/zero-rated income. Default true (25%).'],
                'customer'      => ['type' => 'string', 'description' => 'Who was invoiced / paid you.'],
                'kind'          => ['type' => 'string', 'enum' => ['invoice', 'other'], 'description' => 'invoice for a faktura, other for revenue without a formal invoice.'],
                'source'        => ['type' => 'string', 'enum' => ['nemhandel', 'private', 'manual'], 'description' => 'nemhandel = public-body invoice via NemHandel; private = private client; else manual.'],
                'doc_number'    => ['type' => 'string', 'description' => 'Invoice number, only if the user has one. Do not invent.'],
                'date'          => ['type' => 'string', 'description' => 'Invoice/issue date YYYY-MM-DD (drives the moms period). Omit for today.'],
                'paid'          => ['type' => 'boolean', 'description' => 'True if already paid.'],
                'paid_date'     => ['type' => 'string', 'description' => 'Date paid YYYY-MM-DD, if known.'],
                'category'      => ['type' => 'string', 'enum' => Income::CATEGORIES, 'description' => 'Best-fit income category.'],
                'currency'      => ['type' => 'string', 'description' => 'ISO currency, default DKK.'],
                'note'          => ['type' => 'string', 'description' => 'Optional short note.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $num = static fn (string $k): ?float => isset($arguments[$k]) && $arguments[$k] !== '' && $arguments[$k] !== null && is_numeric($arguments[$k])
            ? (float) $arguments[$k] : null;

        $ex    = $num('amount_ex_vat');
        $vat   = $num('vat');
        $total = $num('total');
        if ($ex === null && $vat === null && $total === null) {
            return ['error' => 'How much was the income (amount, with or without moms)?'];
        }

        $vatable = !isset($arguments['vatable']) || (bool) $arguments['vatable'];
        [$exD, $vatD, $totD] = Income::deriveVat($ex, $vat, $total, $vatable);

        $date   = isset($arguments['date']) && trim((string) $arguments['date']) !== ''
            ? (string) $arguments['date'] : Income::today();
        $paidOn = null;
        if (!empty($arguments['paid']) || (isset($arguments['paid_date']) && trim((string) $arguments['paid_date']) !== '')) {
            $paidOn = isset($arguments['paid_date']) && trim((string) $arguments['paid_date']) !== ''
                ? (string) $arguments['paid_date'] : $date;
        }

        $source = (string) ($arguments['source'] ?? 'manual');
        $id = $this->income->create($userId, [
            'kind'          => $arguments['kind'] ?? 'invoice',
            'customer'      => $arguments['customer'] ?? null,
            'issued_at'     => $date,
            'paid_at'       => $paidOn,
            'amount_ex_vat' => $exD,
            'vat'           => $vatD,
            'total'         => $totD,
            'currency'      => $arguments['currency'] ?? 'DKK',
            'category'      => $arguments['category'] ?? null,
            'doc_number'    => $arguments['doc_number'] ?? null,
            'note'          => $arguments['note'] ?? null,
        ], $source);

        $this->audit->log($userId, 'income', $id, 'create', [
            'total' => $totD, 'vat' => $vatD, 'customer' => $arguments['customer'] ?? null, 'source' => $source,
        ]);

        $row = $this->income->get($userId, $id);

        return [
            'created' => true,
            'id'      => $id,
            'status'  => 'draft',
            '_render' => $row !== null ? $this->income->card($row) : null,
        ];
    }
}
