<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Receipts;

/**
 * Tool: produce a CSV export link for confirmed expenses in a period (for the
 * accountant). The heavy lifting is the download endpoint; this returns the URL.
 */
final class ExportExpensesCsv implements Tool
{
    public function __construct(private Receipts $receipts)
    {
    }

    public function name(): string
    {
        return 'export_expenses_csv';
    }

    public function description(): string
    {
        return 'Creates a CSV download of the user\'s confirmed expenses for a period (for their '
            . 'accountant/bookkeeping). Returns a link — present it to the user as a clickable link to '
            . 'download. Use for "export my expenses", "download receipts for Q3".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'period'   => [
                    'type'        => 'string',
                    'enum'        => ['this_month', 'last_month', 'this_quarter', 'this_year', 'all'],
                    'description' => 'Which period. Defaults to this_year. Ignored if from/to given.',
                ],
                'from'     => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD (overrides period).'],
                'to'       => ['type' => 'string', 'description' => 'End date YYYY-MM-DD (overrides period).'],
                'category' => ['type' => 'string', 'enum' => Receipts::CATEGORIES, 'description' => 'Limit to one category.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        [$from, $to, $label] = GetExpenses::resolveRange(
            (string) ($arguments['period'] ?? 'this_year'),
            isset($arguments['from']) ? (string) $arguments['from'] : null,
            isset($arguments['to']) ? (string) $arguments['to'] : null
        );
        $category = isset($arguments['category']) ? Receipts::normalizeCategory((string) $arguments['category']) : null;

        // How many rows the export will contain (so the model can say if it's empty).
        $count = $this->receipts->summary($userId, $from, $to, $category)['count'];

        $query = array_filter([
            'from'     => $from,
            'to'       => $to,
            'category' => $category,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $url = $this->baseUrl() . '/api/receipts-export.php' . ($query !== [] ? '?' . http_build_query($query) : '');

        return ['period' => $label, 'count' => $count, 'download_url' => $url];
    }

    private function baseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';

        return 'https://' . $host;
    }
}
