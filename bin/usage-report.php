<?php

declare(strict_types=1);

/**
 * Usage & performance report — reads the diagnostics JSON that every assistant turn
 * already persists (messages.diagnostics: routing, model, per-tool calls, timing) and
 * rolls it up so we can answer "which tools are actually used?", "where does the time
 * go?", and "how often does the model really chain tools?" without new instrumentation.
 *
 *     php bin/usage-report.php               # all history
 *     php bin/usage-report.php --days=7      # only the last 7 days
 *     php bin/usage-report.php --days=30 --top=25
 *
 * Read-only: it never writes, and it aggregates on tool NAME + timing + ok/error only.
 * It deliberately does NOT print call args or message content — those can contain
 * sensitive data (health, personal). Dev-run against the live DB, like the cron scripts.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Database;

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

// ---- load -------------------------------------------------------------------
$db  = Database::get();
$sql = "SELECT diagnostics, created_at
        FROM messages
        WHERE role = 'assistant' AND diagnostics IS NOT NULL";
if ($days !== null) {
    $sql .= ' AND created_at >= (NOW() - INTERVAL :days DAY)';
}
$stmt = $db->prepare($sql);
if ($days !== null) {
    $stmt->bindValue(':days', $days, PDO::PARAM_INT);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    echo "No diagnostics found" . ($days !== null ? " in the last {$days} days" : "") . ".\n";
    exit(0);
}

// ---- accumulators -----------------------------------------------------------
$turns        = 0;
$firstAt      = null;
$lastAt       = null;
$models       = [];   // model => count
$routing      = [];   // group => count
$callsPerTurn = [];   // n => count of turns with n tool calls

$lat = [   // metric => list of ms (for percentiles)
    'total'  => [], 'gemini' => [], 'tool' => [], 'app' => [],
];
$geminiCalls = []; // list of round-trips per turn
$reqKb       = [];

/** @var array<string, array{count:int, ok:int, err:int, ms:int[], rounds:int[]}> */
$tools  = [];
/** @var array<string, int> */
$errors = []; // "tool: message" => count

$pctl = static function (array $v, float $p): int {
    if ($v === []) {
        return 0;
    }
    sort($v);
    $idx = (int) ceil($p * count($v)) - 1;
    return (int) $v[max(0, min($idx, count($v) - 1))];
};

// ---- scan -------------------------------------------------------------------
foreach ($rows as $row) {
    $d = json_decode((string) $row['diagnostics'], true);
    if (!is_array($d)) {
        continue;
    }
    $turns++;
    $at = (string) $row['created_at'];
    $firstAt = $firstAt === null ? $at : min($firstAt, $at);
    $lastAt  = $lastAt === null ? $at : max($lastAt, $at);

    $model = $d['model'] ?? '(none)';
    $models[$model] = ($models[$model] ?? 0) + 1;

    foreach ((array) ($d['routing'] ?? ['(unknown)']) as $g) {
        $routing[(string) $g] = ($routing[(string) $g] ?? 0) + 1;
    }

    $t = $d['timing'] ?? [];
    if (isset($t['total_ms']))  { $lat['total'][]  = (int) $t['total_ms']; }
    if (isset($t['gemini_ms'])) { $lat['gemini'][] = (int) $t['gemini_ms']; }
    if (isset($t['tools_ms']))  { $lat['tool'][]   = (int) $t['tools_ms']; }
    if (isset($t['app_ms']))    { $lat['app'][]    = (int) $t['app_ms']; }
    if (isset($t['gemini_calls'])) { $geminiCalls[] = (int) $t['gemini_calls']; }
    if (isset($t['req_kb']))    { $reqKb[] = (int) $t['req_kb']; }

    $calls = (array) ($d['calls'] ?? []);
    $n = count($calls);
    $callsPerTurn[$n] = ($callsPerTurn[$n] ?? 0) + 1;

    foreach ($calls as $c) {
        $name = (string) ($c['name'] ?? '(unknown)');
        if (!isset($tools[$name])) {
            $tools[$name] = ['count' => 0, 'ok' => 0, 'err' => 0, 'ms' => [], 'rounds' => []];
        }
        $tools[$name]['count']++;
        $ok = (bool) ($c['ok'] ?? true);
        $tools[$name][$ok ? 'ok' : 'err']++;
        if (isset($c['ms']))    { $tools[$name]['ms'][] = (int) $c['ms']; }
        if (isset($c['round'])) { $tools[$name]['rounds'][] = (int) $c['round']; }
        if (!$ok && isset($c['error'])) {
            $key = $name . ': ' . mb_substr((string) $c['error'], 0, 80);
            $errors[$key] = ($errors[$key] ?? 0) + 1;
        }
    }
}

// ---- render -----------------------------------------------------------------
$line = str_repeat('-', 72);
echo "\n" . $line . "\n";
echo "  KACHOW USAGE & PERFORMANCE REPORT\n";
echo "  " . ($days !== null ? "last {$days} days" : "all history")
    . "  |  {$turns} turns  |  {$firstAt}  ->  {$lastAt}\n";
echo $line . "\n";

// Models
echo "\nMODEL (turns per model chosen)\n";
arsort($models);
foreach ($models as $m => $c) {
    printf("  %-32s %6d  (%s)\n", $m, $c, pct($c, $turns));
}

// Routing
echo "\nROUTING (how the toolset was scoped)\n";
arsort($routing);
foreach ($routing as $g => $c) {
    printf("  %-32s %6d  (%s)\n", $g, $c, pct($c, $turns));
}

// Latency
echo "\nLATENCY  ms: p50 / p95 / max     (avg gemini round-trips per turn: "
    . ($geminiCalls === [] ? 'n/a' : sprintf('%.2f', array_sum($geminiCalls) / count($geminiCalls)))
    . ")\n";
foreach (['total' => 'total turn', 'gemini' => '  gemini http', 'tool' => '  tool exec', 'app' => '  app/db/etc'] as $k => $label) {
    printf("  %-14s %6d / %6d / %6d\n", $label, $pctl($lat[$k], 0.50), $pctl($lat[$k], 0.95), $pctl($lat[$k], 1.0));
}
if ($reqKb !== []) {
    printf("  %-14s %6d / %6d / %6d  KB (request payload sent to Gemini)\n",
        'req size', $pctl($reqKb, 0.50), $pctl($reqKb, 0.95), $pctl($reqKb, 1.0));
}

// Chaining depth
echo "\nTOOL CALLS PER TURN (chaining depth)\n";
ksort($callsPerTurn);
foreach ($callsPerTurn as $n => $c) {
    $label = $n === 0 ? '0 (answered directly, no tool)' : ($n === 1 ? '1 tool' : "{$n} tools (chained)");
    printf("  %-32s %6d  (%s)\n", $label, $c, pct($c, $turns));
}

// Most-used tools
echo "\nMOST-USED TOOLS  (count | err% | ms p50/p95 | usual round)\n";
uasort($tools, static fn($a, $b) => $b['count'] <=> $a['count']);
$shown = 0;
foreach ($tools as $name => $s) {
    if ($shown++ >= $top) {
        printf("  ... and %d more tools\n", count($tools) - $top);
        break;
    }
    $errPct = $s['count'] > 0 ? round(100 * $s['err'] / $s['count']) : 0;
    $rounds = $s['rounds'] !== [] ? array_sum($s['rounds']) / count($s['rounds']) : 0;
    printf("  %-26s %5d | %3d%% | %5d/%5d | r%.1f\n",
        mb_strimwidth($name, 0, 26),
        $s['count'], $errPct, $pctl($s['ms'], 0.50), $pctl($s['ms'], 0.95), $rounds);
}

// Errors
if ($errors !== []) {
    echo "\nTOP TOOL ERRORS\n";
    arsort($errors);
    $shown = 0;
    foreach ($errors as $key => $c) {
        if ($shown++ >= 15) {
            break;
        }
        printf("  %5dx  %s\n", $c, $key);
    }
} else {
    echo "\nNo tool errors recorded. \n";
}

echo "\n" . $line . "\n\n";

function pct(int $n, int $total): string
{
    return $total > 0 ? sprintf('%4.1f%%', 100 * $n / $total) : '  n/a';
}
