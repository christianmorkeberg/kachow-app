<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One export (from ExportData::build) as a FILE: the printable page, a CSV or a PDF. Shared by api/export.php (the links) and the email
 * attachment, so what is previewed, downloaded and attached is the same document.
 */
final class ExportPage
{
    /**
     * @param array<string, mixed> $built ExportData::build() result (no error)
     * @param array<string, mixed> $spec the export spec
     * @return array{body:string, mime:string, filename:string}
     */
    public static function render(array $built, array $spec, string $format, string $personName = ''): array
    {
        $lang   = ($spec['lang'] ?? 'da') === 'en' ? 'en' : 'da';
        $stamp  = date('Y-m-d');
        $title  = trim((string) ($spec['title'] ?? ''));
        $base   = preg_replace('/[^a-z0-9_-]+/i', '', (string) ($spec['source'] ?? 'export')) ?: 'export';
        $extra  = [];
        foreach (is_array($spec['header'] ?? null) ? $spec['header'] : [] as $k => $v) {
            if (is_string($k) && (is_scalar($v) || $v === null)) {
                $extra[mb_substr($k, 0, 60)] = mb_substr((string) $v, 0, 160);
            }
        }
        $footer = mb_substr((string) ($spec['footer'] ?? ''), 0, 600);

        if (isset($built['sheet'])) {
            $opt  = $built['options'];
            $args = is_array($spec['args'] ?? null) ? $spec['args'] : [];
            $days = $built['sheet']['days'];
            // The period is the one asked for (from / to), not the first and last day that happened to have hours.
            $fromD = !empty($args['from']) && strtotime((string) $args['from']) ? (string) $args['from'] : $days[0]['date'];
            $toD   = !empty($args['to']) && strtotime((string) $args['to']) ? (string) $args['to'] : $days[count($days) - 1]['date'];
            $head  = [
                'title' => mb_substr($title, 0, 120), 'person' => mb_substr(trim((string) ($spec['from_name'] ?? '')), 0, 120) ?: $personName,
                'customer' => mb_substr((string) ($spec['customer'] ?? ''), 0, 120), 'job' => mb_substr((string) ($args['place'] ?? ''), 0, 120),
                'period' => date('d.m.Y', strtotime($fromD)) . ' – ' . date('d.m.Y', strtotime($toD)), 'made' => date('d.m.Y'), 'extra' => $extra,
            ];
            if ($format === 'csv') {
                $rows = [];
                $lines = Timesheet::metaLines($head, $lang, $spec['labels'] ?? []);
                foreach ($lines as $k => $v) {
                    $rows[] = [$k, $v];
                }
                if ($lines !== []) {
                    $rows[] = [];
                }

                return ['body' => self::csv(array_merge($rows, Timesheet::csvRows($built['sheet'], $lang, $opt))), 'mime' => 'text/csv; charset=utf-8', 'filename' => 'timesheet_' . $stamp . '.csv'];
            }
            $html = Timesheet::html($built['sheet'], $lang, $head, $opt);

            return $format === 'pdf'
                ? ['body' => PdfRenderer::fromHtml($html), 'mime' => 'application/pdf', 'filename' => 'timesheet_' . $stamp . '.pdf']
                : ['body' => $html, 'mime' => 'text/html; charset=utf-8', 'filename' => 'timesheet_' . $stamp . '.html'];
        }

        if ($format === 'csv') {
            $rows = [];
            foreach ($extra as $k => $v) {
                if ($v !== '') {
                    $rows[] = [$k, $v];
                }
            }
            if ($rows !== []) {
                $rows[] = [];
            }
            $rows[] = $built['labels'];
            foreach ($built['rows'] as $r) {
                $rows[] = $r;
            }
            if ($built['total'] !== null) {
                $rows[] = $built['total'];
            }

            return ['body' => self::csv($rows), 'mime' => 'text/csv; charset=utf-8', 'filename' => $base . '_' . $stamp . '.csv'];
        }
        $html = DataExport::html(
            $title !== '' ? mb_substr($title, 0, 120) : DataExport::label((string) ($spec['source'] ?? 'Export')),
            ['columns' => $built['columns'], 'labels' => $built['labels'], 'rows' => $built['rows'], 'total' => $built['total']],
            $extra + ['Date' => date('d.m.Y')],
            $footer,
            $lang
        );

        return $format === 'pdf'
            ? ['body' => PdfRenderer::fromHtml($html), 'mime' => 'application/pdf', 'filename' => $base . '_' . $stamp . '.pdf']
            : ['body' => $html, 'mime' => 'text/html; charset=utf-8', 'filename' => $base . '_' . $stamp . '.html'];
    }

    /** Excel-friendly CSV: semicolon, UTF-8 BOM. @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $out = fopen('php://memory', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($out, $r, ';');
        }
        rewind($out);
        $s = (string) stream_get_contents($out);
        fclose($out);

        return $s;
    }
}
