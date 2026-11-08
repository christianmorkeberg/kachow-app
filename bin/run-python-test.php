<?php

declare(strict_types=1);

/**
 * run_python: the request GeminiClient builds (code_execution on its own call), extraction of what the sandbox ran, and the tool's behaviour — all without network.
 *   php bin/run-python-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;
use App\Assistant\GeminiClient;
use App\Tools\RunPython;
use App\Tools\ToolSelector;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$ran = json_encode(['candidates' => [['content' => ['parts' => [
    ['text' => 'Let me compute it.'],
    ['executableCode' => ['language' => 'PYTHON', 'code' => "print(round(100 * 1.25, 2))"]],
    ['codeExecutionResult' => ['outcome' => 'OUTCOME_OK', 'output' => "125.0\n"]],
    ['text' => 'It comes to 125.'],
]]]]]);

echo "1) the request\n";
$sent = null;
$client = new GeminiClient('k', ['m1'], function (string $url, array $payload, array $headers, ?int $t = null) use (&$sent, $ran) { $sent = $payload; return [200, $ran]; });
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]], [], null, null, null, null, null, false, true);
check('code_execution is attached, as an empty JSON object', str_contains(json_encode($sent['tools']), '"code_execution":{}'), json_encode($sent['tools']));
check('no function declarations alongside it', !isset($sent['tools'][0]['function_declarations']));
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]]);
check('a normal call carries no tools', !isset($sent['tools']));

echo "2) extraction\n";
$runs = GeminiClient::extractCodeRuns(json_decode($ran, true));
check('the code, its output and outcome are paired', count($runs) === 1 && str_contains($runs[0]['code'], 'print') && trim($runs[0]['output']) === '125.0' && $runs[0]['outcome'] === 'OUTCOME_OK', json_encode($runs));
check('no code → no runs', GeminiClient::extractCodeRuns(['candidates' => [['content' => ['parts' => [['text' => 'x']]]]]]) === []);

echo "3) the tool\n";
$tool = new RunPython($client);
$r = $tool->execute(['task' => 'What is 25% on top of 100?'], 1);
check('returns the computed result, the code and a note', ($r['computed'] ?? false) === true && $r['result'] === '125.0' && str_contains($r['code'], 'print') && str_contains($r['note'], 'computed'), json_encode($r));
check('data is passed with the task', (function () use ($ran) {
    $seen = '';
    $t = new RunPython(new GeminiClient('k', ['m1'], function ($u, array $p) use (&$seen, $ran) { $seen = json_encode($p['contents']); return [200, $ran]; }));
    $t->execute(['task' => 'Sum these', 'data' => '1,2,3'], 1);
    return str_contains($seen, 'Sum these') && str_contains($seen, '1,2,3');
})());
check('an empty task is refused', isset($tool->execute(['task' => ' '], 1)['error']));
check('too much data is refused before any call', isset($tool->execute(['task' => 'x', 'data' => str_repeat('a', 20001)], 1)['error']));

$noCode = new RunPython(new GeminiClient('k', ['m1'], fn () => [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => 'It is about 125.']]]]]])]));
$e = $noCode->execute(['task' => 'x'], 1);
check('an answer without code is NOT presented as computed', isset($e['error']) && !isset($e['computed']) && str_contains($e['error'], 'guess'), json_encode($e));

$failed = new RunPython(new GeminiClient('k', ['m1'], fn () => [200, json_encode(['candidates' => [['content' => ['parts' => [['executableCode' => ['code' => '1/0']], ['codeExecutionResult' => ['outcome' => 'OUTCOME_FAILED', 'output' => 'ZeroDivisionError']]]]]]])]));
$e = $failed->execute(['task' => 'x'], 1);
check('failed code is an error with the code shown, never a figure', isset($e['error']) && !isset($e['computed']) && str_contains((string) ($e['output'] ?? ''), 'ZeroDivision'), json_encode($e));

$down = new RunPython(new GeminiClient('k', ['m1'], fn () => [500, '{"error":{"message":"boom"}}']));
$e = $down->execute(['task' => 'x'], 1);
check('a failing call says so and forbids a made-up figure', isset($e['error']) && str_contains($e['error'], 'computed'));

echo "4) wiring\n";
check('the tool is named run_python and takes task + data', $tool->name() === 'run_python' && array_keys($tool->parameters()['properties']) === ['task', 'data']);
check('it is routed by keywords (solnedgang / sunset reach the compute group)', in_array('compute', ToolSelector::matchGroups('hvornår er solnedgang i Aarhus næste fredag'), true) && in_array('compute', ToolSelector::matchGroups('what time is sunset next Friday'), true));
check('the system rule asks before noting a dev idea', str_contains((new ReflectionClassConstant(AssistantLoop::class, 'GAP_RULE'))->getValue(), 'ask') && str_contains((new ReflectionClassConstant(AssistantLoop::class, 'GAP_RULE'))->getValue(), 'Only call note_dev_idea after they say yes'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
