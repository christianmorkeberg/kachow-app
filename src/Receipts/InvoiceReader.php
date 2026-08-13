<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Assistant\GeminiClient;

/**
 * Reads an issued sales invoice (a faktura the business SENT — image or PDF) with
 * Gemini (multimodal + JSON mode) into income fields. Best-effort: anything unreadable
 * comes back null for the user to fill on the confirm card — it never blocks saving.
 *
 * Key distinction vs a receipt: on an invoice the "customer" is the RECIPIENT the
 * invoice is addressed to (the buyer), not the sender/seller (the user's own business).
 */
final class InvoiceReader
{
    private const SYSTEM =
        'You extract data from an ISSUED SALES INVOICE (a "faktura" the business SENT to a customer). '
        . 'Return ONLY JSON matching the schema. customer = the CUSTOMER the invoice is billed to (the '
        . 'buyer/recipient) — NOT the sender/seller/issuer. invoice_number = the invoice number as printed. '
        . 'date = the invoice/issue date as YYYY-MM-DD. amount_ex_vat = the subtotal BEFORE VAT/moms. '
        . 'vat = the VAT/moms amount (0 if none). total = the grand total INCLUDING VAT. currency = ISO '
        . 'code (DKK for "kr"). Danish invoices are common (moms = VAT, usually 25%). If a value is '
        . 'unreadable, use null (or 0 for vat).';

    public function __construct(private GeminiClient $gemini)
    {
    }

    /**
     * @return array{customer:?string, doc_number:?string, issued_at:?string, amount_ex_vat:?float, vat:?float, total:?float, currency:string}
     */
    public function read(string $filePath, string $mime): array
    {
        $blank = ['customer' => null, 'doc_number' => null, 'issued_at' => null,
            'amount_ex_vat' => null, 'vat' => null, 'total' => null, 'currency' => 'DKK'];

        $data = @file_get_contents($filePath);
        if ($data === false || $data === '') {
            return $blank;
        }

        $contents = [[
            'role'  => 'user',
            'parts' => [
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($data)]],
                ['text' => 'Extract this invoice into the JSON schema.'],
            ],
        ]];

        $config = [
            'responseMimeType' => 'application/json',
            'responseSchema'   => [
                'type'       => 'OBJECT',
                'properties' => [
                    'customer'       => ['type' => 'STRING'],
                    'invoice_number' => ['type' => 'STRING'],
                    'date'           => ['type' => 'STRING'],
                    'amount_ex_vat'  => ['type' => 'NUMBER'],
                    'vat'            => ['type' => 'NUMBER'],
                    'total'          => ['type' => 'NUMBER'],
                    'currency'       => ['type' => 'STRING'],
                ],
            ],
        ];

        try {
            $response = $this->gemini->generate($contents, [], self::SYSTEM, null, $config);
            $parsed   = json_decode(GeminiClient::extractText($response), true);
        } catch (\Throwable $e) {
            return $blank;
        }
        if (!is_array($parsed)) {
            return $blank;
        }

        $num = static fn (string $k): ?float => isset($parsed[$k]) && is_numeric($parsed[$k]) ? (float) $parsed[$k] : null;

        return [
            'customer'      => isset($parsed['customer']) && $parsed['customer'] !== '' ? (string) $parsed['customer'] : null,
            'doc_number'    => isset($parsed['invoice_number']) && $parsed['invoice_number'] !== '' ? (string) $parsed['invoice_number'] : null,
            'issued_at'     => isset($parsed['date']) && $parsed['date'] !== '' ? (string) $parsed['date'] : null,
            'amount_ex_vat' => $num('amount_ex_vat'),
            'vat'           => $num('vat'),
            'total'         => $num('total'),
            'currency'      => isset($parsed['currency']) && $parsed['currency'] !== '' ? strtoupper((string) $parsed['currency']) : 'DKK',
        ];
    }
}
