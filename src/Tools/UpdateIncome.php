<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\Income;

/**
 * Tool: correct an existing income draft/entry — the user fixes the amount, says it
 * was incl. vs excl. VAT, renames the customer, etc. Use this INSTEAD of add_income
 * when amending something you just created, so a correction doesn't spawn a duplicate
 * draft.
 */
final class UpdateIncome implements Tool
{
    public function __construct(
        private Income $income,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'update_income';
    }

    public function description(): string
    {
        return 'Corrects an EXISTING income entry (invoice/revenue) — use when the user fixes a draft you '
            . 'just created: "no it\'s 10k INCLUDING moms", "the customer is actually X", "change the date". '
            . 'Call this with the entry id (from the add_income result or a get_income row) rather than '
            . 'creating a new draft with add_income. Supply only the fields that change; give whichever '
            . 'amounts the user restated (total incl. moms, amount_ex_vat, and/or vat) and the tool re-derives '
            . 'the rest at 25% (moms = total ÷ 5). Do NOT invent an id — if you are unsure which entry, ask.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id'            => ['type' => 'integer', 'description' => 'The income entry id to correct.'],
                'total'         => ['type' => 'number', 'description' => 'Corrected gross amount incl. moms.'],
                'amount_ex_vat' => ['type' => 'number', 'description' => 'Corrected net amount excl. moms.'],
                'vat'           => ['type' => 'number', 'description' => 'Corrected moms amount.'],
                'vatable'       => ['type' => 'boolean', 'description' => 'False only for VAT-free income. Default true (25%).'],
                'customer'      => ['type' => 'string', 'description' => 'Corrected customer.'],
                'kind'          => ['type' => 'string', 'enum' => ['invoice', 'other'], 'description' => 'invoice or other revenue.'],
                'doc_number'    => ['type' => 'string', 'description' => 'Invoice number, if the user gives one.'],
                'date'          => ['type' => 'string', 'description' => 'Corrected invoice/issue date YYYY-MM-DD.'],
                'paid'          => ['type' => 'boolean', 'description' => 'Set true if now paid, false to mark unpaid again.'],
                'paid_date'     => ['type' => 'string', 'description' => 'Date paid YYYY-MM-DD, if given.'],
                'category'      => ['type' => 'string', 'enum' => Income::CATEGORIES, 'description' => 'Corrected category.'],
                'currency'      => ['type' => 'string', 'description' => 'ISO currency.'],
                'note'          => ['type' => 'string', 'description' => 'Corrected note.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        if ($id <= 0 || $this->income->get($userId, $id) === null) {
            return ['error' => 'I couldn\'t find that income entry to update.'];
        }

        $num = static fn (string $k): ?float => isset($arguments[$k]) && $arguments[$k] !== '' && $arguments[$k] !== null && is_numeric($arguments[$k])
            ? (float) $arguments[$k] : null;

        $fields = [];
        foreach (['customer', 'kind', 'doc_number', 'category', 'currency', 'note'] as $f) {
            if (array_key_exists($f, $arguments)) {
                $fields[$f] = $arguments[$f];
            }
        }
        if (isset($arguments['date']) && trim((string) $arguments['date']) !== '') {
            $fields['issued_at'] = (string) $arguments['date'];
        }

        // Re-derive the amount triple only if the user restated an amount.
        $ex = $num('amount_ex_vat'); $vat = $num('vat'); $total = $num('total');
        if ($ex !== null || $vat !== null || $total !== null) {
            $vatable = !isset($arguments['vatable']) || (bool) $arguments['vatable'];
            [$exD, $vatD, $totD] = Income::deriveVat($ex, $vat, $total, $vatable);
            $fields['amount_ex_vat'] = $exD;
            $fields['vat']           = $vatD;
            $fields['total']         = $totD;
        }

        // Paid status: paid=true (or a paid_date) sets the date; paid=false clears it.
        if (array_key_exists('paid', $arguments) || (isset($arguments['paid_date']) && trim((string) $arguments['paid_date']) !== '')) {
            if (isset($arguments['paid']) && $arguments['paid'] === false) {
                $fields['paid_at'] = null;
            } else {
                $fields['paid_at'] = isset($arguments['paid_date']) && trim((string) $arguments['paid_date']) !== ''
                    ? (string) $arguments['paid_date']
                    : ($this->income->get($userId, $id)['issued_at'] ?? Income::today());
            }
        }

        if ($fields === []) {
            return ['error' => 'What should I change on it?'];
        }

        $this->income->update($userId, $id, $fields);
        $this->audit->log($userId, 'income', $id, 'update', ['fields' => array_keys($fields)]);

        $row = $this->income->get($userId, $id);

        return [
            'updated' => true,
            'id'      => $id,
            '_render' => $row !== null ? $this->income->card($row) : null,
        ];
    }
}
