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
        . 'is VAT). If a value is unreadable, use null (or 0 for vat).';

    public function __construct(private GeminiClient $gemini)
    {
    }

    /**
     * @return array{vendor:?string, purchased_at:?string, total:?float, vat:?float, currency:string, category:?string}
     */
    public function read(string $imagePath, string $mime): array
    {
        $blank = ['vendor' => null, 'purchased_at' => null, 'total' => null, 'vat' => null, 'currency' => 'DKK', 'category' => null];

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
        ];
    }
}
