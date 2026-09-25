<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Tool: draw a general bar/line chart from numbers the model already obtained from other
 * tools this conversation — the "draw whatever I want" escape hatch (report #23), so a
 * chart the app has no dedicated card for (income per month, workout volume, mileage per
 * destination, anything tabular) is still possible. The tool only renders; it computes
 * nothing, so the rule is strict: every value must come from a tool result.
 */
final class ShowChart implements Tool
{
    private const MAX_POINTS = 60;
    private const MAX_SERIES = 6;

    public function name(): string
    {
        return 'show_chart';
    }

    public function description(): string
    {
        return 'Draws a chart (bar or line, one or several series, optionally stacked) of numbers you '
            . 'ALREADY have from tool results in this conversation — for any "draw / chart / graph / plot / '
            . 'visualise …" request ("tegn", "vis som graf", "lav et diagram") that no dedicated chart covers: '
            . 'e.g. income or expenses per month (get_income / get_expenses), mileage per destination, '
            . 'workout sets, anything you can put in a table. First fetch the data with the right read '
            . 'tool(s), then call this with the values. STRICT: every value must come from a tool result — '
            . 'never invent, estimate, interpolate or pad; if something is missing, fetch it or leave it out '
            . 'and say so. Prefer dedicated charts where they exist: get_work_summary for work hours (it '
            . 'computes exact totals and stacks workplaces), get_workout_progress for exercise progression. '
            . 'After drawing, give a one-line takeaway instead of repeating the numbers.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title'   => ['type' => 'string', 'description' => 'Short chart title, in the user\'s language.'],
                'type'    => ['type' => 'string', 'enum' => ['bar', 'line'], 'description' => 'bar (default) for categories/periods; line for a trend over time.'],
                'labels'  => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'X-axis labels, in order (e.g. ["Jul","Aug","Sep"]). Max ' . self::MAX_POINTS . '.',
                ],
                'series'  => [
                    'type'        => 'array',
                    'description' => 'One or more data series (max ' . self::MAX_SERIES . '), each with one value per label.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'name'   => ['type' => 'string', 'description' => 'Series name (legend).'],
                            'values' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'One number per label, same order.'],
                        ],
                        'required'   => ['name', 'values'],
                    ],
                ],
                'unit'    => ['type' => 'string', 'description' => 'Unit shown with values, e.g. "kr", "h", "kg", "km". Optional.'],
                'stacked' => ['type' => 'boolean', 'description' => 'Bar charts with several series: stack them (parts of a whole) instead of side by side.'],
                'source'  => ['type' => 'string', 'description' => 'Which tool result(s) the numbers came from, e.g. "get_income". Required — no source, no chart.'],
            ],
            'required' => ['title', 'labels', 'series', 'source'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $source = trim((string) ($arguments['source'] ?? ''));
        if ($source === '') {
            return ['error' => 'Say which tool result the numbers came from (source). Fetch the data first if you have not.'];
        }

        $labels = array_values(array_map(
            static fn ($l): string => mb_substr(trim((string) $l), 0, 24),
            is_array($arguments['labels'] ?? null) ? $arguments['labels'] : []
        ));
        $n = count($labels);
        if ($n === 0 || $n > self::MAX_POINTS) {
            return ['error' => 'Give between 1 and ' . self::MAX_POINTS . ' labels.'];
        }

        $series = [];
        foreach (is_array($arguments['series'] ?? null) ? $arguments['series'] : [] as $i => $sr) {
            if (!is_array($sr) || !is_array($sr['values'] ?? null)) {
                return ['error' => 'Each series needs a name and a values list.'];
            }
            $vals = array_values($sr['values']);
            if (count($vals) !== $n) {
                return ['error' => 'Series "' . ($sr['name'] ?? $i + 1) . '" has ' . count($vals) . ' values but there are ' . $n . ' labels — one value per label.'];
            }
            foreach ($vals as $v) {
                if (!is_numeric($v)) {
                    return ['error' => 'Values must be numbers (got "' . (is_scalar($v) ? (string) $v : gettype($v)) . '").'];
                }
            }
            $series[] = [
                'name'   => mb_substr(trim((string) ($sr['name'] ?? 'Series ' . ($i + 1))), 0, 40),
                'values' => array_map(static fn ($v): float => round((float) $v, 2), $vals),
            ];
        }
        if ($series === [] || count($series) > self::MAX_SERIES) {
            return ['error' => 'Give between 1 and ' . self::MAX_SERIES . ' series.'];
        }

        $type = ($arguments['type'] ?? 'bar') === 'line' ? 'line' : 'bar';

        return [
            'shown'   => true,
            'points'  => $n,
            'series'  => count($series),
            'note'    => 'The chart is on screen — give a short takeaway, do not list the values again.',
            '_render' => [
                'kind'    => 'chart',
                'title'   => mb_substr(trim((string) ($arguments['title'] ?? 'Chart')), 0, 80),
                'type'    => $type,
                'labels'  => $labels,
                'series'  => $series,
                'unit'    => mb_substr(trim((string) ($arguments['unit'] ?? '')), 0, 12),
                'stacked' => $type === 'bar' && count($series) > 1 && filter_var($arguments['stacked'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'source'  => mb_substr($source, 0, 80),
            ],
        ];
    }
}
