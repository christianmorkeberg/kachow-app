<?php

declare(strict_types=1);

/**
 * Pure unit tests for Support\ToolMemory::digest — the ids of what earlier turns created,
 * carried into the system prompt so a correction updates instead of duplicating (report #31).
 * No DB. Run: php bin/tool-memory-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\ToolMemory;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/** A stored message row. */
function row(string $role, string $content = '', ?string $tool = null): array
{
    return ['role' => $role, 'content' => $content, 'tool_name' => $tool];
}

function toolRow(string $tool, array $result): array
{
    return row('tool', (string) json_encode($result), $tool);
}

// ---------------------------------------------------------------- report #31
echo "the invoice draft from report #31 stays findable\n";
$history = [
    row('user', 'Det er 650 eks moms, men jeg laver fakturaer til et selskab i nemfaktura'),
    toolRow('add_income', ['created' => true, 'id' => 7, 'status' => 'draft', 'customer' => 'Client Z',
        'total' => 56858.75]),
    row('assistant', 'Jeg har oprettet indtægtskladden …'),
    row('user', 'jeg har rundet op til 70 timer. Kan jeg give dig en pdf-kopi?'),
];
$d = ToolMemory::digest($history);
check('the id survives into the next turn', str_contains($d, 'id 7'), $d);
check('names the tool that made it', str_contains($d, 'add_income'), $d);
check('carries the status', str_contains($d, 'status: draft'), $d);
check('carries the customer so two drafts can be told apart', str_contains($d, 'Client Z'), $d);
check('tells the model to update rather than duplicate',
    str_contains($d, 'never create a second entry'), $d);

echo "\nreads are left out — only writes carry ids worth reusing\n";
$d = ToolMemory::digest([
    toolRow('get_income', ['period' => 'All time', 'count' => 0, 'items' => []]),
    toolRow('get_work_hours', ['total_label' => '7h 30m', 'ongoing' => false]),
]);
check('nothing to remember', $d === '', $d);

echo "\nfailed calls are not remembered as if they had worked\n";
$d = ToolMemory::digest([
    toolRow('update_income', ['error' => "I couldn't find that income entry to update."]),
]);
check('errors skipped', $d === '', $d);

echo "\na later write to the same id replaces the earlier line\n";
$d = ToolMemory::digest([
    toolRow('add_income', ['created' => true, 'id' => 7, 'status' => 'draft', 'total' => 56858.75]),
    toolRow('add_income', ['created' => true, 'id' => 7, 'status' => 'draft', 'total' => 56875.0]),
]);
check('one line only', substr_count($d, 'add_income') === 1, $d);
check('the newest amount wins', str_contains($d, '56875') && !str_contains($d, '56858.75'), $d);

echo "\ndifferent entities both survive\n";
$d = ToolMemory::digest([
    toolRow('add_income', ['created' => true, 'id' => 7, 'status' => 'draft']),
    toolRow('log_work_event', ['recorded' => true, 'kind' => 'in', 'at' => '08:30', 'day' => 'today']),
    toolRow('save_place', ['saved' => true, 'id' => 3, 'name' => 'Office']),
]);
check('all three listed', str_contains($d, 'add_income') && str_contains($d, 'log_work_event')
    && str_contains($d, 'save_place'), $d);
check('deleted/changed ids too', str_contains($d, 'id 3'), $d);

echo "\nnothing bulky or private leaks in\n";
$d = ToolMemory::digest([
    toolRow('get_location_day', [
        'id' => 1,
        'created' => true,
        'points' => array_fill(0, 500, [55.0, 12.0, '08:00', 5, null]),
        'note'   => str_repeat('very long text ', 40),
        'stats'  => ['nested' => ['deep' => 'value']],
    ]),
]);
check('the point list is dropped', !str_contains($d, '55'), $d);
check('long text is dropped', !str_contains($d, 'very long text'), $d);
check('nested structures are dropped', !str_contains($d, 'deep'), $d);
check('digest stays small', mb_strlen($d) < 1800, (string) mb_strlen($d));

echo "\nonly the last few writes are kept\n";
$many = [];
for ($i = 1; $i <= 30; $i++) {
    $many[] = toolRow('add_expense', ['created' => true, 'id' => $i]);
}
$d = ToolMemory::digest($many);
check('capped at 10 lines', substr_count($d, '- add_expense') === 10, (string) substr_count($d, '- add_expense'));
check('keeps the newest', str_contains($d, 'id 30'), $d);
check('drops the oldest', !str_contains($d, 'id 1,') && !str_contains($d, 'id 1' . PHP_EOL), $d);

echo "\nan empty or tool-free history produces nothing\n";
check('empty', ToolMemory::digest([]) === '');
check('chat only', ToolMemory::digest([row('user', 'hej'), row('assistant', 'hej!')]) === '');
check('malformed json', ToolMemory::digest([row('tool', 'not json', 'add_income')]) === '');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
