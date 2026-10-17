<?php

declare(strict_types=1);

/**
 * Web search: the consent gate (Support\WebSearchConsent), the grounded request GeminiClient builds,
 * source extraction, and the web_search tool — all without network (injectable transport).
 *   php bin/web-search-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\GeminiClient;
use App\Support\WebSearchConsent;
use App\Tools\WebSearch;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) an explicit request counts\n";
foreach ([
    'search the web for the opening hours of Cafe X',
    'can you google that?', 'google opening hours for Cafe X', 'look it up online please',
    'check online if they are open on Sunday', 'do a web search on it',
    'søg på nettet efter åbningstider', 'kan du søge det op på nettet', 'slå det op på nettet', 'tjek online om de har åbent',
    'google det lige',
] as $msg) {
    check($msg, WebSearchConsent::granted($msg));
}

echo "2) the offer chip counts, in both languages\n";
check('Search the web', WebSearchConsent::granted('Search the web'));
check('Søg på nettet', WebSearchConsent::granted('Søg på nettet'));
check('trailing punctuation', WebSearchConsent::granted('Search the web.'));

echo "3) a bare yes counts ONLY as the answer to an offer\n";
$offer = 'I am not sure of their opening hours. Want me to search the web?';
check('yes after an offer', WebSearchConsent::granted('yes', $offer));
check('ja tak after a Danish offer', WebSearchConsent::granted('ja tak', 'Jeg er ikke sikker. Skal jeg søge på nettet?'));
check('yes with no offer before it', !WebSearchConsent::granted('yes', 'Your lights are on.'));
check('yes after a statement that is not a question', !WebSearchConsent::granted('yes', 'I searched the web and found nothing.'));
check('yes with nothing before it', !WebSearchConsent::granted('yes'));

echo "4) ordinary messages never trigger it\n";
foreach ([
    'what time is it', 'add milk to the shopping list', 'is the cafe open on sunday', 'what are the opening hours of Cafe X',
    'tænd lyset i stuen', 'hvornår har restauranten åbent', 'No thanks', 'Nej tak', 'no', 'what is on my calendar',
    'I read online that it is closed', 'the web page is slow',
] as $msg) {
    check($msg, !WebSearchConsent::granted($msg, 'Want me to search the web?'));
}

echo "5) the request GeminiClient builds\n";
$sent = null;
$answer = json_encode(['candidates' => [[
    'content' => ['parts' => [['text' => 'Open 11-22 daily.']]],
    'groundingMetadata' => ['groundingChunks' => [
        ['web' => ['uri' => 'https://redirect.example/1', 'title' => 'cafex.example']],
        ['web' => ['uri' => 'https://redirect.example/2', 'title' => 'maps.example']],
        ['web' => ['uri' => 'https://redirect.example/3', 'title' => 'cafex.example']],
        ['web' => ['uri' => '', 'title' => 'broken']],
    ]],
]]]);
$client = new GeminiClient('k', ['m1'], function (string $url, array $payload, array $headers, ?int $t = null) use (&$sent, $answer) {
    $sent = $payload;
    return [200, $answer];
});
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]], [], null, null, null, null, null, true);
check('google_search tool is attached', isset($sent['tools'][0]['google_search']));
check('it serialises as an empty JSON object, not a list', str_contains(json_encode($sent['tools']), '"google_search":{}'), json_encode($sent['tools']));
check('no function declarations alongside it', !isset($sent['tools'][0]['function_declarations']));
$client->generate([['role' => 'user', 'parts' => [['text' => 'q']]]]);
check('a normal call carries no tools at all', !isset($sent['tools']));

echo "6) sources\n";
$src = GeminiClient::extractSources(json_decode($answer, true));
check('de-duplicated by site, broken entries dropped', count($src) === 2 && $src[0]['title'] === 'cafex.example', json_encode($src));
check('none when there is no grounding', GeminiClient::extractSources(['candidates' => [['content' => []]]]) === []);

echo "7) the tool\n";
$tool = new WebSearch($client);
$r = $tool->execute(['query' => 'Cafe X Copenhagen opening hours'], 1);
check('returns the answer and the site names', $r['answer'] === 'Open 11-22 daily.' && $r['sources'] === ['cafex.example', 'maps.example'], json_encode($r));
check('tells the model to present it as from the web', str_contains($r['note'], 'from the web'));
check('an empty query is refused', isset($tool->execute(['query' => ' '], 1)['error']));
$noSources = new WebSearch(new GeminiClient('k', ['m1'], fn () => [200, json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Maybe.']]]]]])]));
check('an answer with no sources is flagged unverified', str_contains($noSources->execute(['query' => 'x'], 1)['note'], 'unverified'));
$empty = new WebSearch(new GeminiClient('k', ['m1'], fn () => [200, json_encode(['candidates' => [['content' => ['parts' => []]]]])]));
check('an empty answer is an error, not a guess', isset($empty->execute(['query' => 'x'], 1)['error']));
$down = new WebSearch(new GeminiClient('k', ['m1'], fn () => [500, '{"error":{"message":"boom"}}']));
$e = $down->execute(['query' => 'x'], 1);
check('a failing search says so and forbids answering from memory', isset($e['error']) && str_contains($e['error'], 'memory'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
