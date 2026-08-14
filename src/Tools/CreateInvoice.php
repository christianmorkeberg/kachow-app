<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\BookkeepingAudit;
use App\Data\Income;
use App\Data\UserSettings;
use DateTimeImmutable;

/**
 * Tool: generate a compliant invoice for a PRIVATE client. Assigns the next gapless
 * K-YEAR-NNN number, books it as income (moms applies from the issue date), and produces
 * a printable invoice document. NOT for public bodies — those go via NemHandel and are
 * only recorded (add_income).
 */
final class CreateInvoice implements Tool
{
    public function __construct(
        private Income $income,
        private UserSettings $settings,
        private BookkeepingAudit $audit,
    ) {
    }

    public function name(): string
    {
        return 'create_invoice';
    }

    public function description(): string
    {
        return 'Generates a proper invoice for a PRIVATE client and books it as income: assigns Kachow\'s next '
            . 'gapless invoice number (K-YEAR-NNN), adds 25% moms (salgsmoms), and creates a printable invoice '
            . 'document. Use for "make/create/generate an invoice for …", "send X an invoice for …", "lav en '
            . 'faktura til …", "opret en faktura". Provide the customer and the line items (each a description, '
            . 'quantity and unit price EX-VAT). Do NOT use for public-sector clients (those use NemHandel — '
            . 'record with add_income) or to log an invoice you already sent (add_income). The company/sender '
            . 'details come from the saved company profile (set_company_profile).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'customer' => ['type' => 'string', 'description' => 'Client name (optionally with address on following lines).'],
                'line_items' => [
                    'type'  => 'array',
                    'description' => 'The invoice lines. Each: description, qty (default 1), unit_price (ex-VAT, DKK).',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'qty'         => ['type' => 'number'],
                            'unit_price'  => ['type' => 'number', 'description' => 'Price per unit, EXCLUDING VAT.'],
                        ],
                        'required' => ['description', 'unit_price'],
                    ],
                ],
                'issued_at' => ['type' => 'string', 'description' => 'Issue date YYYY-MM-DD. Omit for today.'],
                'due_days'  => ['type' => 'integer', 'description' => 'Payment terms in days from issue (default 14).'],
                'note'      => ['type' => 'string', 'description' => 'Optional note shown on the invoice.'],
                'vatable'   => ['type' => 'boolean', 'description' => 'Whether 25% moms applies (default true).'],
            ],
            'required' => ['customer', 'line_items'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $lines = Income::normalizeInvoiceLines($arguments['line_items'] ?? []);
        if ($lines === []) {
            return ['error' => 'At least one invoice line (description + unit price) is required.'];
        }

        $issued = isset($arguments['issued_at']) && trim((string) $arguments['issued_at']) !== ''
            ? date('Y-m-d', strtotime((string) $arguments['issued_at']) ?: time())
            : Income::today();
        $dueDays = isset($arguments['due_days']) ? max(0, (int) $arguments['due_days']) : 14;
        $due     = (new DateTimeImmutable($issued))->modify('+' . $dueDays . ' days')->format('Y-m-d');

        $id = $this->income->createInvoice($userId, [
            'customer'   => $arguments['customer'] ?? '',
            'issued_at'  => $issued,
            'due_at'     => $due,
            'note'       => $arguments['note'] ?? null,
            'vatable'    => ($arguments['vatable'] ?? true) !== false,
            'line_items' => $lines,
        ]);
        $this->audit->log($userId, 'income', $id, 'create', ['source' => 'private', 'invoice' => true]);

        $row  = $this->income->get($userId, $id);
        $card = $row !== null ? $this->income->card($row) : null;

        $profile = $this->settings->companyProfile($userId);
        $missing = ($profile['name'] === '' || $profile['cvr'] === '');

        return [
            'created'        => true,
            'invoice_number' => $card['doc_number'] ?? '',
            'total'          => $card['total'] ?? null,
            'due_at'         => $due,
            'invoice_url'    => $card['invoice_url'] ?? null,
            'profile_incomplete' => $missing,
            'note'           => $missing
                ? 'Invoice created, but your company name/CVR isn\'t set — set it with set_company_profile so the invoice is complete.'
                : 'Invoice created and booked. Open it to review and print/send.',
            '_render'        => $card,
        ];
    }
}
