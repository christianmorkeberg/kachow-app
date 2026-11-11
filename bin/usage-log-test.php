<?php

declare(strict_types=1);

/**
 * Gemini usage and cost: what a response's usageMetadata becomes, the turn totals, the sink, the cost maths (cached tokens at the cached price, thinking as output) and the
 * overview by purpose / model / day — against an in-memory SQLite database. (setPrice uses MySQL's ON DUPLICATE KEY and is not run here.)
 *   php bin/usage-log-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\GeminiClient;
use App\Data\UsageLog;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$resp = ['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]], 'usageMetadata' => [
    'promptTokenCount' => 1000, 'cachedContentTokenCount' => 400, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 200, 'toolUsePromptTokenCount' => 30, 'totalTokenCount' => 1280]];

echo "1) reading the response\n";
check('the five counts, as ints', GeminiClient::usageFrom($resp) === ['prompt' => 1000, 'cached' => 400, 'output' => 50, 'thoughts' => 200, 'tool' => 30], json_encode(GeminiClient::usageFrom($resp)));
check('a response without usageMetadata is all zeros', GeminiClient::usageFrom(['candidates' => []]) === ['prompt' => 0, 'cached' => 0, 'output' => 0, 'thoughts' => 0, 'tool' => 0]);

echo "2) the client: turn totals and the sink\n";
$seen = [];
$client = new GeminiClient('k', ['m1'], fn () => [200, json_encode($resp)], null, null, function (string $purpose, string $model, array $u) use (&$seen): void { $seen[] = [$purpose, $model, $u['prompt']]; }, 'chat');
GeminiClient::resetTurnUsage();
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]]);
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]], [], null, null, null, null, null, false, false, 'web_search');
check('the sink hears every call with its purpose and model (the default, then the override)', $seen === [['chat', 'm1', 1000], ['web_search', 'm1', 1000]], json_encode($seen));
$t = GeminiClient::turnUsage();
check('the turn adds up both calls', $t['calls'] === 2 && $t['prompt'] === 2000 && $t['cached'] === 800 && $t['output'] === 100 && $t['thoughts'] === 400 && $t['tool'] === 60, json_encode($t));
GeminiClient::resetTurnUsage();
check('reset starts a new turn', GeminiClient::turnUsage()['calls'] === 0);
$boom = new GeminiClient('k', ['m1'], fn () => [200, json_encode($resp)], null, null, function (): void { throw new RuntimeException('db down'); });
check('a failing sink never breaks the call', is_array($boom->generate([['role' => 'user', 'parts' => [['text' => 'q']]]])));

echo "3) cost\n";
$price = ['input_per_m' => 1.0, 'cached_per_m' => 0.25, 'output_per_m' => 4.0];
$u = ['prompt' => 1000, 'cached' => 400, 'output' => 50, 'thoughts' => 200, 'tool' => 30];
// fresh input 600 × 1 + cached 400 × 0.25 + tool 30 × 1 = 730 ; output (50 + 200) × 4 = 1000 ; total 1730 / 1e6
check('cached at the cached price, thinking as output, tool-use as input', abs((UsageLog::cost($u, $price) ?? -1) - 0.00173) < 1e-12, (string) UsageLog::cost($u, $price));
check('no price for the model → no cost (never a made-up number)', UsageLog::cost($u, null) === null);
check('cached can never exceed the prompt', abs((UsageLog::cost(['prompt' => 100, 'cached' => 900, 'output' => 0, 'thoughts' => 0, 'tool' => 0], $price) ?? -1) - 100 * 0.25 / 1e6) < 1e-12);

echo "4) the log and the overview\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE gemini_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, purpose TEXT, model TEXT, prompt_tokens INT DEFAULT 0, cached_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0, thought_tokens INT DEFAULT 0, tool_tokens INT DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE gemini_prices (model TEXT PRIMARY KEY, input_per_m REAL, cached_per_m REAL, output_per_m REAL, updated_at TEXT)');
$log = new UsageLog($db);
UsageLog::forUser(7);
$log->record('chat', 'flash', ['prompt' => 5000, 'cached' => 0, 'output' => 100, 'thoughts' => 0, 'tool' => 0]);
$log->record('chat', 'flash', ['prompt' => 3000, 'cached' => 1000, 'output' => 80, 'thoughts' => 120, 'tool' => 0]);
$log->record('run_python', 'flash', ['prompt' => 400, 'cached' => 0, 'output' => 60, 'thoughts' => 0, 'tool' => 0]);
$log->record('recruiter', 'lite', ['prompt' => 2000, 'cached' => 0, 'output' => 150, 'thoughts' => 0, 'tool' => 0], 99);
$log->record('chat', 'flash', ['prompt' => 0, 'cached' => 0, 'output' => 0, 'thoughts' => 0, 'tool' => 0]);   // reported nothing: not a row
check('a row per call that reported something, with the user', (int) $db->query('SELECT COUNT(*) FROM gemini_usage')->fetchColumn() === 4 && (int) $db->query("SELECT user_id FROM gemini_usage WHERE purpose = 'run_python'")->fetchColumn() === 7 && (int) $db->query("SELECT user_id FROM gemini_usage WHERE purpose = 'recruiter'")->fetchColumn() === 99);
$o = $log->overview(null);
check('without prices: tokens are there, cost is not claimed', $o['ok'] === true && $o['totals']['calls'] === 4 && $o['totals']['prompt'] === 10400 && $o['totals']['unpriced_calls'] === 4 && $o['totals']['cost'] === 0.0, json_encode($o['totals']));
$db->exec("INSERT INTO gemini_prices VALUES ('flash', 1.0, 0.25, 4.0, NULL)");
$o = $log->overview(30);
$by = array_column($o['by_purpose'], null, 'key');
check('by purpose: chat is the 2 chat calls, priced', $by['chat']['calls'] === 2 && $by['chat']['prompt'] === 8000 && $by['chat']['cached'] === 1000 && $by['chat']['unpriced_calls'] === 0, json_encode($by['chat']));
// chat: call1 5000×1 + 100×4 = 5400 ; call2 2000×1 + 1000×0.25 + (80+120)×4 = 3050 → 8450/1e6
check('chat cost = 0.00845', abs($by['chat']['cost'] - 0.00845) < 1e-12, (string) $by['chat']['cost']);
check('a model without a price stays unpriced while the others are priced', $by['recruiter']['unpriced_calls'] === 1 && $o['totals']['unpriced_calls'] === 1 && $o['totals']['calls'] === 4);
check('most expensive purpose first', $o['by_purpose'][0]['key'] === 'chat');
check('by model and by day are there', count($o['by_model']) === 2 && count($o['by_day']) === 1 && $o['by_day'][0]['calls'] === 4);
check('prices() reads them back', $log->prices() === ['flash' => ['input_per_m' => 1.0, 'cached_per_m' => 0.25, 'output_per_m' => 4.0]]);
check('a missing table is an error message, not an exception', (new UsageLog(new PDO('sqlite::memory:')))->overview(7)['ok'] === false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
