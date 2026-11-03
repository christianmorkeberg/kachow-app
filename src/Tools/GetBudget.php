<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LoanReports;

/** Tool: a 12-month budget projected from the last 12 months of actuals. */
final class GetBudget implements Tool
{
    public function __construct(private LoanReports $reports)
    {
    }

    public function name(): string
    {
        return 'get_budget';
    }

    public function description(): string
    {
        return 'A 12-MONTH BUDGET (budget) for the next 12 months, projected from the average of the last 12 months of income and expenses (ex moms), optionally moved by '
            . 'growth_pct or overridden with income_per_month / expenses_per_month. Use for "lav et budget", "budget til banken", "12 måneders budget". It is a projection from actuals — '
            . 'say so, and say which figures it used. For a file call export_data with source get_budget (language da).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'growth_pct'         => ['type' => 'number', 'description' => 'Percent to move the averages, e.g. 10 or -5. Default 0.'],
            'income_per_month'   => ['type' => 'number', 'description' => 'Use this monthly income (ex moms) instead of the average.'],
            'expenses_per_month' => ['type' => 'number', 'description' => 'Use this monthly expense total (ex moms) instead of the average.'],
        ], 'required' => []];
    }

    public function execute(array $arguments, int $userId): array
    {
        $last = date('Y-m-01', strtotime('first day of last month') ?: time());
        $b    = $this->reports->budget(
            $userId, $last, (float) ($arguments['growth_pct'] ?? 0),
            isset($arguments['income_per_month']) ? (float) $arguments['income_per_month'] : null,
            isset($arguments['expenses_per_month']) ? (float) $arguments['expenses_per_month'] : null
        );

        return ['grundlag' => 'gennemsnit af de seneste 12 måneder', 'indtaegter_pr_maaned' => $b['avg_income'], 'udgifter_pr_maaned' => $b['avg_expenses'], 'maaneder' => $b['rows']];
    }
}
