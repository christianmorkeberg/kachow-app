<?php

declare(strict_types=1);

/**
 * What the assistant's tools are really used for, from the tool_call_log table (every entry point; never arguments).
 *   php bin/tool-log-report.php [days=30]
 * Prints calls per tool and source. ToolCallLog::unused(\$registeredNames, \$days) lists registered tools never called (candidates to drop from the wide set).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Diagnostics\ToolCallLog;

$days = max(1, (int) ($argv[1] ?? 30));
$log  = new ToolCallLog();
$rows = $log->summary($days);

printf("Tool calls, last %d days (%d tool/source pairs)\n\n", $days, count($rows));
printf("%-34s %-10s %7s %6s %7s  %s\n", 'tool', 'source', 'calls', 'errors', 'avg ms', 'last');
foreach ($rows as $r) {
    printf("%-34s %-10s %7d %6d %7d  %s\n", $r['tool'], $r['source'], $r['calls'], $r['errors'], $r['avg_ms'], $r['last_at']);
}
