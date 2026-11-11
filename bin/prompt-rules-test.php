<?php

declare(strict_types=1);

/**
 * The system prompt carries the core rules on every turn and an area's rules only when the message is about that area — also with the full tool list (wide
 * mode, as in production). The clock is last. Nothing that a mistake taught us is lost: the anchors below must all still be in the rules. SQLite, scripted Gemini.
 *   php bin/prompt-rules-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;
use App\Assistant\GeminiClient;
use App\Assistant\PromptRules;
use App\Data\Conversations;
use App\Tools\Tool;
use App\Tools\ToolRegistry;
use App\Tools\ToolSelector;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INT, role TEXT, content TEXT, tool_name TEXT, card TEXT, diagnostics TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE app_flags (flag_key TEXT PRIMARY KEY, flag_value TEXT)');
$rp = new ReflectionProperty(App\Database::class, 'instance');
$rp->setAccessible(true);
$rp->setValue(null, $db);

$noop = new class implements Tool {
    public function name(): string { return 'get_weather'; }
    public function description(): string { return 'Weather.'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $arguments, int $userId): array { return ['ok' => true]; }
};

/** The system instruction the model got for $msg (wide = the full tool list, as in production). */
function systemFor(string $msg, bool $wide, Tool $tool): string
{
    global $db;
    $sys = '';
    $client = new GeminiClient('k', ['m1'], function (string $url, array $payload) use (&$sys): array {
        $sys = (string) ($payload['system_instruction']['parts'][0]['text'] ?? '');

        return [200, json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']]])];
    });
    $registry = new ToolRegistry();
    $registry->register($tool);
    $loop = new AssistantLoop($client, $registry, new Conversations($db), wideTools: $wide);
    $loop->handle(1, (new Conversations($db))->start(1), $msg);

    return $sys;
}

echo "1) size\n";
$orig = 19486;   // the single prompt before the split (2026-11-11)
check('the core is well under half of the old prompt', strlen(PromptRules::CORE) < $orig / 2, (string) strlen(PromptRules::CORE));
foreach (array_unique(PromptRules::MODULES) as $t) {
    if (strlen($t) > 1400) { check('an area rule stays short', false, substr($t, 0, 40)); }
}
check('every area rule is short (≤ 1,400 chars)', true);
check('every area key is a real routing group (a typo would silently drop a rule)', array_diff(array_keys(PromptRules::MODULES), ToolSelector::groupNames()) === [], implode(',', array_diff(array_keys(PromptRules::MODULES), ToolSelector::groupNames())));

echo "2) the lessons are still there (anchors from the reports behind each rule)\n";
$all = PromptRules::all();
foreach ([
    'reply in the language of the user\'s latest message', 'OTHER language', 'never estimate, round', 'ONLY the latest tool result', 'CHAIN tools',
    'in the same step', 'call the matching read tool again', 'unless a tool returned it THIS turn', 'Never end a reply announcing a next step', 'I could not find',
    'Send til udvikler', 'tøm it', 'exactly one thing matches', 'put it back yourself', 'The card IS the display', 'in_source', 'your OWN mistakes',
    'remember_about_me', 'opening hours, prices', '[[suggest: Search the web | No thanks]]', '[[suggest: Søg på nettet | Nej tak]]', 'never search on your own',
    '[[suggest: Yes | No]]', 'noted as a dev idea', 'run_python',
    'SHARED shopping list', '"wishlist" or "gift"', 'get_workout_plan / get_week_plan', 'save_training_program', 'needs BOTH calls', 'Working from home',
    'ONE short follow-up', 'Attach invoice', 'EXACT for SKAT', 'Send button', 'pass it as account', 'Settings → Accounts', 'attribute every number',
    'has not been shared yet', 'CHECK', 'where_am_i says they are home',
] as $anchor) {
    if (!str_contains($all, $anchor)) { check('anchor kept: ' . $anchor, false); }
}
check('all anchors are present in the rules', true);

echo "3) targeted: an area's rules only when the message is about it — in wide mode too\n";
$plain = systemFor('hej, hvordan går det?', true, $noop);
check('small talk carries the core and none of the area rules', str_starts_with($plain, PromptRules::CORE) && !str_contains($plain, 'BOOKS:') && !str_contains($plain, 'EMAIL:') && !str_contains($plain, 'WORK: two separate'), substr($plain, 0, 80));
$books = systemFor('I paid 200 kr for a cable at Elgiganten', true, $noop);
check('an expense brings the books rules (and only those)', str_contains($books, 'BOOKS:') && !str_contains($books, 'EMAIL:') && !str_contains($books, 'WORK: two separate'));
$mail = systemFor('send an email to Alex from my work address', true, $noop);
check('an email brings the email rules', str_contains($mail, 'EMAIL:') && !str_contains($mail, 'BOOKS:'));
$work = systemFor('how much did I work last week?', true, $noop);
check('a work question brings the work rules once (worktime and worklog share it)', substr_count($work, 'WORK: two separate') === 1, (string) substr_count($work, 'WORK: two separate'));
$narrow = systemFor('I paid 200 kr for a cable', false, $noop);
check('keyword-routed turns get the same area rules', str_contains($narrow, 'BOOKS:'));

echo "4) order: the unchanged part first, the clock last\n";
check('the prompt starts with the core rules', str_starts_with($books, PromptRules::CORE));
check('the current time is the very last part', (bool) preg_match('/Current local time for the user: [^\n]+$/', $books), substr($books, -120));
check('the area rules come before the clock', strpos($books, 'BOOKS:') < strpos($books, 'Current local time'));
check('a photo turn without a caption still gets the receipt / list rules', str_contains(PromptRules::forGroups(PromptRules::IMAGE_GROUPS), 'BOOKS:') && str_contains(PromptRules::forGroups(PromptRules::IMAGE_GROUPS), 'SHARED shopping list'));

echo "5) the size of a turn\n";
printf("     core %d chars · small talk %d · expense %d · work %d (the old prompt was %d before the per-turn parts)\n", strlen(PromptRules::CORE), strlen($plain), strlen($books), strlen($work), $orig);
check('a small-talk turn is under 45% of the old prompt', strlen($plain) < $orig * 0.45, (string) strlen($plain));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
