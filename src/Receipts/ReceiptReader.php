<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Assistant\GeminiClient;
use App\Data\Receipts;

/**
 * Reads a receipt image with Gemini (multimodal + JSON mode) into header-level
 * expense fields. Best-effort: anything it can't read comes back null/empty for
 * the user to fill in on the confirm card — it never blocks saving the receipt.
 */
final class ReceiptReader
{
    private const SYSTEM =
        'You extract expense data from a photo of a receipt. Return ONLY JSON matching the schema. '
        . 'date = the purchase date as YYYY-MM-DD. total = the grand total actually paid, including '
        . 'VAT. vat = the VAT/moms amount (0 if not shown). currency = ISO code (DKK for "kr"). '
        . 'category = the single best fit from the allowed list. Danish receipts are common ("moms" '
        . 'is VAT). line_items = each purchased line: description (the product name as printed), qty '
        . '(quantity, 1 if not shown), amount (the line total for that item, in the same currency). '
        . 'Skip subtotal/VAT/total/discount summary rows — only actual products. If there are no '
        . 'readable line items, return an empty array. If a value is unreadable, use null (or 0 for vat).';

    /** Cap how many line items we keep (a very long receipt won't blow up storage/UI). */
    private const MAX_LINE_ITEMS = 60;

    public function __construct(private GeminiClient $gemini)
    {
    }

    /**
     * @return array{vendor:?string, purchased_at:?string, total:?float, vat:?float, currency:string, category:?string, line_items:array<int,array{description:string,qty:?float,amount:?float}>}
     */
    public function read(string $imagePath, string $mime): array
    {
        $blank = ['vendor' => null, 'purchased_at' => null, 'total' => null, 'vat' => null, 'currency' => 'DKK', 'category' => null, 'line_items' => []];

        $data = @file_get_contents($imagePath);
        if ($data === false || $data === '') {
            return $blank;
        }

        $contents = [[
            'role'  => 'user',
            'parts' => [
                ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($data)]],
                ['text' => 'Extract this receipt into the JSON schema.'],
            ],
        ]];

        $config = [
            'responseMimeType' => 'application/json',
            'responseSchema'   => [
                'type'       => 'OBJECT',
                'properties' => [
                    'vendor'   => ['type' => 'STRING'],
                    'date'     => ['type' => 'STRING'],
                    'total'    => ['type' => 'NUMBER'],
                    'vat'      => ['type' => 'NUMBER'],
                    'currency' => ['type' => 'STRING'],
                    'category' => ['type' => 'STRING', 'enum' => Receipts::CATEGORIES],
                    'line_items' => [
                        'type'  => 'ARRAY',
                        'items' => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'description' => ['type' => 'STRING'],
                                'qty'         => ['type' => 'NUMBER'],
                                'amount'      => ['type' => 'NUMBER'],
                            ],
                        ],
                    ],
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

        return [
            'vendor'       => isset($parsed['vendor']) && $parsed['vendor'] !== '' ? (string) $parsed['vendor'] : null,
            'purchased_at' => isset($parsed['date']) && $parsed['date'] !== '' ? (string) $parsed['date'] : null,
            'total'        => isset($parsed['total']) && is_numeric($parsed['total']) ? (float) $parsed['total'] : null,
            'vat'          => isset($parsed['vat']) && is_numeric($parsed['vat']) ? (float) $parsed['vat'] : null,
            'currency'     => isset($parsed['currency']) && $parsed['currency'] !== '' ? strtoupper((string) $parsed['currency']) : 'DKK',
            'category'     => Receipts::normalizeCategory($parsed['category'] ?? null),
            'line_items'   => $this->cleanLineItems($parsed['line_items'] ?? null),
        ];
    }

    /**
     * Sanitises the model's line items into a compact, bounded list. Drops rows with
     * no description; caps count and string length so a misread can't bloat storage.
     *
     * @return array<int, array{description:string, qty:?float, amount:?float}>
     */
    private function cleanLineItems(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $out[] = [
                'description' => mb_substr($desc, 0, 120),
                'qty'         => isset($item['qty']) && is_numeric($item['qty']) ? (float) $item['qty'] : null,
                'amount'      => isset($item['amount']) && is_numeric($item['amount']) ? round((float) $item['amount'], 2) : null,
            ];
            if (count($out) >= self::MAX_LINE_ITEMS) {
                break;
            }
        }

        return $out;
    }
}
