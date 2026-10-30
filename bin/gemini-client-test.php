<?php

declare(strict_types=1);

/**
 * Pure unit tests for GeminiClient::generate()'s request-building — no network, via the
 * injectable transport. Covers the migration off deprecated sampling params (temperature/
 * top_p/top_k) and thinking_budget -> thinking_level (Google's Nov 2026 deprecation notices):
 *   php bin/gemini-client-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\GeminiClient;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$canned = json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);

/** Captures the last payload sent, returns a canned 200 OK. */
function capturingTransport(?array &$capturedPayload): callable
{
    return function (string $url, array $payload, array $headers, ?int $timeoutMs = null) use (&$capturedPayload) {
        $capturedPayload = $payload;
        return [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]])];
    };
}

echo "1) no thinking config, no generationConfig override -> generationConfig is omitted entirely\n";
$payload = null;
$client = new GeminiClient('k', ['gemini-3.6-flash'], capturingTransport($payload));
$client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
check('no temperature key sent', !isset($payload['generationConfig']['temperature']), json_encode($payload));
check('generationConfig key omitted when nothing to configure', !array_key_exists('generationConfig', $payload), json_encode($payload));

echo "2) thinking_budget set -> sent as thinkingConfig.thinkingBudget, no temperature\n";
$payload = null;
$client = new GeminiClient('k', ['gemini-3.6-flash'], capturingTransport($payload), 512);
$client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
check('thinkingBudget sent', ($payload['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null) === 512, json_encode($payload));
check('no thinkingLevel key when only budget set', !isset($payload['generationConfig']['thinkingConfig']['thinkingLevel']), json_encode($payload));
check('no temperature/top_p/top_k anywhere', !isset($payload['generationConfig']['temperature'])
    && !isset($payload['generationConfig']['top_p']) && !isset($payload['generationConfig']['top_k']), json_encode($payload));

echo "3) thinking_level set -> sent as thinkingConfig.thinkingLevel, budget NOT sent even if also set\n";
$payload = null;
$client = new GeminiClient('k', ['gemini-3.6-flash'], capturingTransport($payload), 512, 'low');
$client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
check('thinkingLevel sent', ($payload['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'low', json_encode($payload));
check('thinkingBudget NOT sent when level is set (avoid conflicting fields)',
    !isset($payload['generationConfig']['thinkingConfig']['thinkingBudget']), json_encode($payload));

echo "4) caller's own generationConfig (e.g. includeThoughts) is preserved alongside thinking config\n";
$payload = null;
$client = new GeminiClient('k', ['gemini-3.6-flash'], capturingTransport($payload), 512);
$client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]], [], null, null, ['thinkingConfig' => ['includeThoughts' => true]]);
check('includeThoughts preserved', ($payload['generationConfig']['thinkingConfig']['includeThoughts'] ?? null) === true, json_encode($payload));
check('thinkingBudget still merged in alongside it', ($payload['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null) === 512, json_encode($payload));

echo "5) fromEnv() reads GEMINI_THINKING_LEVEL and GEMINI_THINKING_BUDGET, no GEMINI_TEMPERATURE support\n";
$prevKey = $_ENV['GEMINI_API_KEY'] ?? null;
$prevLevel = $_ENV['GEMINI_THINKING_LEVEL'] ?? null;
$_ENV['GEMINI_API_KEY'] = 'k';
$_ENV['GEMINI_THINKING_LEVEL'] = 'high';
$payload = null;
$client = GeminiClient::fromEnv(capturingTransport($payload));
$client->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]);
check('GEMINI_THINKING_LEVEL honored via fromEnv', ($payload['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'high', json_encode($payload));
if ($prevKey === null) { unset($_ENV['GEMINI_API_KEY']); } else { $_ENV['GEMINI_API_KEY'] = $prevKey; }
if ($prevLevel === null) { unset($_ENV['GEMINI_THINKING_LEVEL']); } else { $_ENV['GEMINI_THINKING_LEVEL'] = $prevLevel; }

echo "9) out of credits -> its own exception (not a rate limit), other errors unchanged\n";
$errTransport = static fn (int $status, string $msg): callable => static fn (string $u, array $p, array $h, ?int $t = null): array => [$status, json_encode(['error' => ['message' => $msg]])];
$msgCredits = 'Your prepayment credits are depleted. Please go to AI Studio at https://ai.studio/projects to manage your project and billing.';
foreach ([[400, $msgCredits], [429, $msgCredits], [403, 'Billing account is not enabled']] as [$st, $m]) {
    $thrown = null;
    try { (new GeminiClient('k', ['m'], $errTransport($st, $m)))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]); } catch (\Throwable $e) { $thrown = $e; }
    check("HTTP {$st} '" . substr($m, 0, 22) . "…' -> AiCreditsException", $thrown instanceof \App\Assistant\AiCreditsException, $thrown ? get_class($thrown) : 'none');
}
$thrown = null;
try { (new GeminiClient('k', ['m'], $errTransport(429, 'Resource has been exhausted (e.g. check quota).')))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]); } catch (\Throwable $e) { $thrown = $e; }
check('a plain rate limit is still a RateLimitException', $thrown instanceof \App\Assistant\RateLimitException, $thrown ? get_class($thrown) : 'none');
$thrown = null;
try { (new GeminiClient('k', ['m'], $errTransport(400, 'API key not valid.')))->generate([['role' => 'user', 'parts' => [['text' => 'hi']]]]); } catch (\Throwable $e) { $thrown = $e; }
check('another 400 stays a plain RuntimeException', $thrown instanceof \RuntimeException && !($thrown instanceof \App\Assistant\AiCreditsException) && !($thrown instanceof \App\Assistant\RateLimitException), $thrown ? get_class($thrown) : 'none');
check('user text names the fix, English and Danish', str_contains(\App\Assistant\AiCreditsException::userText(false), 'Top up') && str_contains(\App\Assistant\AiCreditsException::userText(true), 'Fyld'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
