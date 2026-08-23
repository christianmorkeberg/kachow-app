<?php

declare(strict_types=1);

/**
 * Usage & performance report — text render of the diagnostics rollup that every
 * assistant turn already persists (messages.diagnostics). The aggregation itself
 * lives in App\Diagnostics\UsageStats, shared with the in-app Insights dashboard
 * (api/usage-stats.php) so the two never drift.
 *
 *     php bin/usage-report.php               # all history
 *     php bin/usage-report.php --days=7      # only the last 7 days
 *     php bin/usage-report.php --days=30 --top=25
 *
 * Read-only; aggregates on tool NAME + timing + ok/error only — never prints call
 * args or message content (which can contain sensitive data). Run on the server.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Diagnostics\UsageStats;

// ---- args -------------------------------------------------------------------
$days = null;
$top  = 40;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = (int) $m[1];
    } elseif (preg_match('/^--top=(\d+)$/', $arg, $m)) {
        $top = max(1, (int) $m[1]);
    } else {
        fwrite(STDERR, "usage-report: unknown argument '{$arg}'\n");
        exit(2);
    }
}

$stats = (new UsageStats())->compute($days);
$turns = (int) $stats['meta']['turns'];

if ($turns === 0) {
    echo "No diagnostics found" . ($days !== null ? " in the last {$days} days" : "") . ".\n";
    exit(0);
}

$pct = static fn(int $n): string => $turns > 0 ? sprintf('%4.1f%%', 100 * $n / $turns) : '  n/a';

// ---- render -----------------------------------------------------------------
$line = str_repeat('-', 72);
echo "\n" . $line . "\n";
echo "  KACHOW USAGE & PERFORMANCE REPORT\n";
echo "  " . ($days !== null ? "last {$days} days" : "all history")
    . "  |  {$turns} turns  |  {$stats['meta']['first_at']}  ->  {$stats['meta']['last_at']}\n";
echo $line . "\n";

echo "\nMODEL (turns per model chosen)\n";
foreach ($stats['models'] as $m) {
    printf("  %-32s %6d  (%s)\n", $m['name'], $m['count'], $pct($m['count']));
}

echo "\nROUTING (how the toolset was scoped)\n";
foreach ($stats['routing'] as $r) {
    printf("  %-32s %6d  (%s)\n", $r['group'], $r['count'], $pct($r['count']));
}

echo "\nLATENCY  ms: p50 / p95 / max     (avg gemini round-trips per turn: "
    . sprintf('%.2f', (float) $stats['gemini_calls_avg']) . ")\n";
$labels = ['total' => 'total turn', 'gemini' => '  gemini http', 'tool' => '  tool exec', 'app' => '  app/db/etc'];
foreach ($labels as $k => $label) {
    $b = $stats['latency'][$k];
    printf("  %-14s %6d / %6d / %6d\n", $label, $b['p50'], $b['p95'], $b['max']);
}
$rk = $stats['latency']['req_kb'];
printf("  %-14s %6d / %6d / %6d  KB (request payload sent to Gemini)\n", 'req size', $rk['p50'], $rk['p95'], $rk['max']);

echo "\nTOOL CALLS PER TURN (chaining depth)\n";
foreach ($stats['calls_per_turn'] as $c) {
    $n = (int) $c['n'];
    $label = $n === 0 ? '0 (answered directly, no tool)' : ($n === 1 ? '1 tool' : "{$n} tools (chained)");
    printf("  %-32s %6d  (%s)\n", $label, $c['count'], $pct($c['count']));
}

echo "\nMOST-USED TOOLS  (count | err% | ms p50/p95 | usual round)\n";
$shown = 0;
$total = count($stats['tools']);
foreach ($stats['tools'] as $s) {
    if ($shown++ >= $top) {
        printf("  ... and %d more tools\n", $total - $top);
        break;
    }
    printf("  %-26s %5d | %3d%% | %5d/%5d | r%.1f\n",
        mb_strimwidth($s['name'], 0, 26), $s['count'], $s['err_pct'], $s['ms_p50'], $s['ms_p95'], $s['avg_round']);
}

if ($stats['errors'] !== []) {
    echo "\nTOP TOOL ERRORS\n";
    foreach ($stats['errors'] as $e) {
        printf("  %5dx  %s: %s\n", $e['count'], $e['tool'], $e['msg']);
    }
} else {
    echo "\nNo tool errors recorded.\n";
}

if ($stats['daily'] !== []) {
    echo "\nDAILY (turns | total p50 | gemini p50 | errors)\n";
    foreach (array_slice($stats['daily'], -14) as $d) {
        printf("  %s  %5d | %6dms | %6dms | %3d\n", $d['date'], $d['turns'], $d['total_p50'], $d['gemini_p50'], $d['errors']);
    }
}

echo "\n" . $line . "\n\n";
