<?php

declare(strict_types=1);

/**
 * Tool targeting in SHADOW mode: the ranking (recent use weighs more, old use fades out), the would-be list (matched areas + top N + new + core), the
 * first-seen seeding (the first run makes every existing tool "old"), the per-turn record and the overview Insights shows — and the hook in the real loop
 * (wide mode), which plans BEFORE the tools run and records after. SQLite, scripted Gemini.
 *   php bin/tool-shadow-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;
use App\Assistant\GeminiClient;
use App\Assistant\ToolRanker;
use App\Data\Conversations;
use App\Diagnostics\ToolShadow;
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

$now = 1_800_000_000;
$day = 86400;

echo "1) ranking\n";
$calls = array_merge(
    array_fill(0, 3, ['tool' => 'add_to_shopping_list', 'at' => $now - 1 * $day]),    // 3 calls yesterday
    array_fill(0, 5, ['tool' => 'get_work_hours', 'at' => $now - 30 * $day]),         // 5 calls a month ago
    [['tool' => 'old_tool', 'at' => $now - 90 * $day]]                                 // outside the window
);
$scores = ToolRanker::scores($calls, $now);
check('recent use beats more but older use (3 yesterday > 5 a month ago)', array_key_first($scores) === 'add_to_shopping_list', json_encode($scores));
check('a call 14 days old counts half', abs(ToolRanker::scores([['tool' => 't', 'at' => $now - 14 * $day]], $now)['t'] - 0.5) < 1e-9);
check('use older than the window does not count', !isset($scores['old_tool']));
check('ranks are 1, 2, …', ToolRanker::ranks($scores) === ['add_to_shopping_list' => 1, 'get_work_hours' => 2]);

echo "2) the would-be list\n";
$sizes = ['a' => 100, 'b' => 200, 'c' => 300, 'remember_about_me' => 50, 'fresh' => 70, 'rare' => 1000];
$ranks = ['a' => 1, 'b' => 15, 'c' => 25];
$plan  = ToolRanker::plan($sizes, ['rare'], $ranks, ['fresh' => $now - 3 * $day, 'a' => $now - 400 * $day], $now);
check('matched, core and new are always in it', $plan['matched'] === ['rare'] && $plan['core'] === ['remember_about_me'] && $plan['new'] === ['fresh'], json_encode($plan));
check('the size grows with N: top 10 = matched + core + new + a', $plan['sizes'][10] === 1000 + 50 + 70 + 100, json_encode($plan['sizes']));
check('top 20 adds b, top 30 adds c, and it never exceeds the full list', $plan['sizes'][20] === 1420 && $plan['sizes'][30] === 1720 && $plan['sizes'][40] === 1720 && $plan['total'] === 1720);
$ev = ToolRanker::evaluate($plan, $ranks, ['c', 'rare', 'web_search', 'c', 'never_used']);
check('evaluate: each called tool once, the web tools not judged', array_column($ev, 'tool') === ['c', 'rare', 'never_used'], json_encode($ev));
check('covered: c only from top 30, rare always (matched), a never-used one never', !ToolRanker::covered($ev[0], 20) && ToolRanker::covered($ev[0], 30) && ToolRanker::covered($ev[1], 10) && !ToolRanker::covered($ev[2], 40));

echo "3) the database side\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE tool_call_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, source TEXT, task_id INT, conversation_id INT, tool TEXT, ok INT, ms INT, error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE tool_first_seen (tool TEXT PRIMARY KEY, first_seen TEXT NOT NULL)');
$db->exec('CREATE TABLE tool_shadow_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, total_chars INT, sizes TEXT, called TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$sh = new ToolShadow($db);
$fs = $sh->firstSeen(['x', 'y'], time());
check('first run: every existing tool counts as old (else all would look new for 3 weeks)', $fs['x'] < time() - 365 * $day && $fs['y'] < time() - 365 * $day);
$fs = $sh->firstSeen(['x', 'y', 'brand_new'], time());
check('later: a tool not seen before is new from now', abs($fs['brand_new'] - time()) < 5 && $fs['x'] < time() - 365 * $day);
$db->exec("INSERT INTO tool_call_log (user_id, source, tool, ok, ms, created_at) VALUES (7, 'chat', 'x', 1, 5, '" . gmdate('Y-m-d H:i:s', time() - 2 * $day) . "'), (8, 'chat', 'y', 1, 5, '" . gmdate('Y-m-d H:i:s') . "')");
$c = $sh->callsFor(7, time());
check('the history is the user\'s own', count($c) === 1 && $c[0]['tool'] === 'x');
$sh->record(7, 10000, [10 => 2000, 20 => 3000, 30 => 4000, 40 => 5000], [['tool' => 'x', 'rank' => 15, 'matched' => false, 'core' => false, 'new' => false]]);
$sh->record(7, 10000, [10 => 2000, 20 => 3000, 30 => 4000, 40 => 5000], []);
$o = $sh->overview(30);
$byN = array_column($o['sizes'], null, 'n');
check('overview: a rank-15 call is a miss at top 10, covered at 20', $byN[10]['missed_turns'] === 1 && $byN[20]['missed_turns'] === 0 && $byN[10]['missed_tools'] === ['x' => 1], json_encode($byN[10]));
check('miss rate and saving per size', $byN[10]['miss_rate'] === 0.5 && $byN[10]['saving'] === 0.8 && $byN[40]['saving'] === 0.5 && $o['turns'] === 2 && $o['turns_with_tools'] === 1, json_encode($byN[40]));
check('no table yet → a message, not an exception', (new ToolShadow(new PDO('sqlite::memory:')))->overview(7)['ok'] === false);

echo "4) in the real loop (wide mode, as in production)\n";
$db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INT, role TEXT, content TEXT, tool_name TEXT, card TEXT, diagnostics TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE app_flags (flag_key TEXT PRIMARY KEY, flag_value TEXT)');
$db->exec('DELETE FROM tool_shadow_log');
$rp = new ReflectionProperty(App\Database::class, 'instance');
$rp->setAccessible(true);
$rp->setValue(null, $db);
$lists = new class implements Tool {
    public function name(): string { return 'add_to_shopping_list'; }
    public function description(): string { return 'Adds to the list.'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => ['item' => ['type' => 'string']]]; }
    public function execute(array $arguments, int $userId): array { return ['added' => true]; }
};
$weather = new class implements Tool {
    public function name(): string { return 'get_current_weather'; }
    public function description(): string { return 'Weather.'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $arguments, int $userId): array { return ['ok' => true]; }
};
$i = 0;
$script = [
    ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'add_to_shopping_list', 'args' => ['item' => 'milk']]]]], 'finishReason' => 'STOP']]],
    ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Added milk.']]], 'finishReason' => 'STOP']]],
];
$sentNames = [];
$client = new GeminiClient('k', ['m1'], function (string $u, array $payload) use (&$i, $script, &$sentNames): array { $sentNames = array_column($payload['tools'][0]['function_declarations'] ?? [], 'name'); return [200, json_encode($script[min($i++, 1)])]; });
$registry = new ToolRegistry();
$registry->register($lists);
$registry->register($weather);
$loop = new AssistantLoop($client, $registry, new Conversations($db), wideTools: true);
$loop->handle(9, (new Conversations($db))->start(9), 'put milk on the list');
$row = $db->query('SELECT * FROM tool_shadow_log')->fetch();
$called = $row ? json_decode($row['called'], true) : [];
check('one shadow row for the turn', $row !== false && (int) $row['user_id'] === 9);
check('the called tool is judged: matched by the message (shopping), and its rank is from BEFORE this turn (none yet)', ($called[0]['tool'] ?? '') === 'add_to_shopping_list' && $called[0]['matched'] === true && $called[0]['rank'] === null, json_encode($called));
check('the full size is the whole list, the targeted sizes are not larger', (int) $row['total_chars'] > 0 && max(json_decode($row['sizes'], true)) <= (int) $row['total_chars']);
check('the model still got the full list (nothing switched): both tools + run_python, web search held back as always', in_array('get_current_weather', $sentNames, true) && in_array('add_to_shopping_list', $sentNames, true) && in_array('run_python', $sentNames, true) && !in_array('web_search', $sentNames, true), json_encode($sentNames));
$loopNarrow = new AssistantLoop(new GeminiClient('k', ['m1'], fn () => [200, json_encode($script[1])]), $registry, new Conversations($db));
$loopNarrow->handle(9, (new Conversations($db))->start(9), 'hello');
check('keyword-routed (not wide) turns are not measured', (int) $db->query('SELECT COUNT(*) FROM tool_shadow_log')->fetchColumn() === 1);
check('the shopping tools are what the matched area gives', in_array('add_to_shopping_list', ToolSelector::toolsFor(['shopping']), true) && ToolSelector::toolsFor(['no_such_group']) === []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
