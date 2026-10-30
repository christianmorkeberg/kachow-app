<?php

declare(strict_types=1);

/** The whole loop with a scripted Gemini: slow tools must not eat the model's time budget (report #66: slow thermostat valves -> "the AI model took too long"). SQLite, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Assistant\AssistantLoop;
use App\Assistant\GeminiClient;
use App\Data\Conversations;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "pdo_sqlite not loaded\n"); exit(2); }
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INT, role TEXT, content TEXT, tool_name TEXT, card TEXT, diagnostics TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

$db->exec('CREATE TABLE app_flags (flag_key TEXT PRIMARY KEY, flag_value TEXT)');
// Anything that reaches for the shared connection (AppFlags …) gets this in-memory database.
$rp = new ReflectionProperty(App\Database::class, 'instance');
$rp->setAccessible(true);
$rp->setValue(null, $db);

$slow = new class implements Tool {
    public function name(): string { return 'note_dev_idea'; }
    public function description(): string { return 'A slow tool (a sleepy thermostat valve).'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']]; }
    public function execute(array $arguments, int $userId): array { usleep(2_200_000); return ['noted' => true, 'id' => 1]; }
};

function text(string $t): array { return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $t]]], 'finishReason' => 'STOP']]]; }
function call(string $name, array $args): array { return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]], 'finishReason' => 'STOP']]]; }

function run(array $script, Tool $note, string $msg, ?callable $configure = null): array
{
    global $db;
    $i = 0;
    $seen = [];
    $client = new GeminiClient('k', ['m1'], function (string $url, array $payload, array $headers, ?int $t = null) use (&$i, $script, &$seen): array {
        $seen[] = json_encode($payload['contents'] ?? []);

        return [200, json_encode($script[min($i++, count($script) - 1)])];
    });
    $registry = new ToolRegistry();
    $registry->register($note);
    $loop = new AssistantLoop($client, $registry, new Conversations($db));
    $conv = (new Conversations($db))->start(1);
    if ($configure !== null) {
        $configure($loop);
    }
    $reply = $loop->handle(1, $conv, $msg);

    return ['reply' => $reply, 'calls' => $loop->lastCalls(), 'gemini' => $i, 'seen' => $seen];
}

echo "1) tools that use up the Gemini budget do not starve the final answer\n";
$_ENV['GEMINI_TURN_BUDGET_S'] = '4';   // Gemini budget 4 s; the tool takes 2.2 s, so < 3 s would be left for the next call without the fix
$t0 = microtime(true);
$r  = run([call('note_dev_idea', ['title' => 'a']), call('note_dev_idea', ['title' => 'b']), text('Begge er sat.')], $slow, 'dev idea: a og b');
check('the final answer arrives (not the "took too long" apology)', $r['reply'] === 'Begge er sat.', $r['reply']);
check('both slow tools ran and the model was asked 3 times', count($r['calls']) === 2 && $r['gemini'] === 3, json_encode([count($r['calls']), $r['gemini']]));
check('the turn really spent time in tools (> 4 s total)', microtime(true) - $t0 > 4.0, (string) round(microtime(true) - $t0, 2));

echo "2) a turn that gets slow tells the page once (onSlow) and carries on\n";
$fired = 0;
$r = run([call('note_dev_idea', ['title' => 'a']), call('note_dev_idea', ['title' => 'b']), text('Begge er sat.')], $slow, 'dev idea: a og b', function (AssistantLoop $loop) use (&$fired): void {
    $loop->onSlow(function () use (&$fired): void { $fired++; }, 1.0);
});
check('onSlow fired exactly once, between steps', $fired === 1, (string) $fired);
check('the turn still finished normally afterwards', $r['reply'] === 'Begge er sat.' && count($r['calls']) === 2, $r['reply']);

$fired = 0;
$r = run([text('Hej!')], $slow, 'hej', function (AssistantLoop $loop) use (&$fired): void {
    $loop->onSlow(function () use (&$fired): void { $fired++; }, 1.0);
});
check('a quick turn never fires it', $fired === 0 && $r['reply'] === 'Hej!', (string) $fired);

$r = run([call('note_dev_idea', ['title' => 'a']), text('Færdig.')], $slow, 'dev idea: a', function (AssistantLoop $loop): void {
    $loop->onSlow(function (): void { throw new RuntimeException('the browser went away'); }, 0.5);
});
check('a failing onSlow callback never breaks the turn', $r['reply'] === 'Færdig.', $r['reply']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
