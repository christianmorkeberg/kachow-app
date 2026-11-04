<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\EntryAttachments;
use App\Data\Income;
use App\Data\Receipts;
use App\Receipts\ReceiptStorage;

/**
 * Tool: attach a file made with export_data (an hour schedule / timesheet, a table) to an income or expense entry as an extra attachment, next to its
 * invoice/receipt file. The user adds any other file (PDF, image, Excel …) with the card's "Add file" button.
 */
final class AttachExportToEntry implements Tool
{
    public function __construct(
        private ExportData $export,
        private Income $income,
        private Receipts $receipts,
        private EntryAttachments $attachments,
        private ReceiptStorage $storage,
    ) {
    }

    public function name(): string
    {
        return 'attach_export_to_entry';
    }

    public function description(): string
    {
        return 'Attaches a file made with export_data — typically the TIMESHEET / hour schedule behind an invoice — to an income entry (kind "income", the invoice) or an '
            . 'expense (kind "expense") as an extra attachment, kept next to the entry\'s own invoice/receipt file (it does NOT replace it). Make the file first with '
            . 'export_data (e.g. the DSB timesheet for the invoiced period), then pass its pdf_url exactly as returned plus the entry id (from add_income / create_invoice / '
            . 'get_income / add_expense). Examples: "attach the timesheet to the invoice", "vedhæft timesedlen til fakturaen". Never combine workplaces into one timesheet — '
            . 'make the file for the workplace the invoice is for.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'kind'       => ['type' => 'string', 'enum' => ['income', 'expense'], 'description' => 'income = an invoice/revenue entry; expense = a receipt.'],
                'entry_id'   => ['type' => 'integer', 'description' => 'The income or expense entry id.'],
                'export_url' => ['type' => 'string', 'description' => 'The pdf_url (or csv_url) from export_data, exactly as returned.'],
                'name'       => ['type' => 'string', 'description' => 'File name to show, e.g. "Timeseddel DSB 30.09-30.10.pdf". Optional.'],
            ],
            'required' => ['kind', 'entry_id', 'export_url'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $kind = ($arguments['kind'] ?? '') === 'income' ? 'income' : 'expense';
        $id   = (int) ($arguments['entry_id'] ?? 0);
        $row  = $id > 0 ? ($kind === 'income' ? $this->income->get($userId, $id) : $this->receipts->get($userId, $id)) : null;
        if ($row === null) {
            return ['error' => 'I could not find that ' . ($kind === 'income' ? 'income entry' : 'expense') . '. Check its id with get_income / get_expenses.'];
        }
        $file = $this->export->attachment(trim((string) ($arguments['export_url'] ?? '')), $userId);
        if (isset($file['error'])) {
            return ['error' => (string) $file['error']];
        }
        $name = trim((string) ($arguments['name'] ?? '')) ?: $file['filename'];
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '') {
            $name .= str_contains($file['mime'], 'csv') ? '.csv' : '.pdf';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'att');
        try {
            file_put_contents((string) $tmp, $file['data']);
            $stored = $this->storage->storeFile($userId, (string) $tmp, strlen($file['data']), $name);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        } finally {
            @unlink((string) $tmp);
        }
        $attId = $this->attachments->add($userId, $kind, $id, $stored['file_ref'], $stored['mime'], $stored['name'], $stored['size']);

        return ['ok' => true, 'attachment_id' => $attId, 'entry_id' => $id, 'kind' => $kind, 'name' => $stored['name']];
    }
}
