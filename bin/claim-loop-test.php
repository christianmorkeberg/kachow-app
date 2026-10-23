<?php

declare(strict_types=1);

/** The whole loop with a scripted Gemini: a claimed save without a tool call is sent back, then done for real — or blocked. SQLite, no network. */
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

$saved = [];
$note  = new class ($saved) implements Tool {
    public function __construct(private array &$saved) {}
    public function name(): string { return 'note_dev_idea'; }
    public function description(): string { return 'Notes a dev idea.'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']]; }
    public function execute(array $arguments, int $userId): array { $this->saved[] = $arguments['title'] ?? ''; return ['noted' => true, 'id' => 68]; }
};

function text(string $t): array { return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $t]]], 'finishReason' => 'STOP']]]; }
function call(string $name, array $args): array { return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $name, 'args' => $args]]]], 'finishReason' => 'STOP']]]; }

function run(array $script, Tool $note, string $msg): array
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
    $reply = $loop->handle(1, $conv, $msg);

    return ['reply' => $reply, 'calls' => $loop->lastCalls(), 'gemini' => $i, 'seen' => $seen];
}

echo "1) claims a save with no tool, then does it for real after the nudge\n";
$r = run([text('Jeg har tilføjet din idé til udviklingsbackloggen: **Vælg mailbox i mail-kortet**'), call('note_dev_idea', ['title' => 'Vælg mailbox i mail-kortet']), text('Noteret som idé #68.')], $note, 'dev ide: vælg mail box i mail card');
check('the tool ran after the nudge and the idea is saved', $saved === ['Vælg mailbox i mail-kortet'] && count($r['calls']) === 1 && $r['calls'][0]['name'] === 'note_dev_idea', json_encode([$saved, $r['calls']]));
check('three model calls: the false claim, the real tool call, the confirmation', $r['gemini'] === 3, (string) $r['gemini']);
check('the user sees the confirmation after the real save, not the false claim', $r['reply'] === 'Noteret som idé #68.', $r['reply']);
check('the model was told nothing had been saved', str_contains(implode('', $r['seen']), 'called NO tool this turn'));

echo "2) an honest reply is not touched\n";
$saved = [];
$r = run([text('Skal jeg gemme den som en udviklingsidé?')], $note, 'jeg kunne tænke mig en bedre mail-visning');
check('a question goes straight through (1 call, no nudge)', $r['gemini'] === 1 && $r['reply'] === 'Skal jeg gemme den som en udviklingsidé?' && $saved === []);

echo "3) a normal save (tool first) is not nudged\n";
$saved = [];
$r = run([call('note_dev_idea', ['title' => 'X']), text('Jeg har noteret idéen.')], $note, 'dev idea: X');
check('tool ran, the confirmation stands (2 calls)', $saved === ['X'] && $r['gemini'] === 2 && $r['reply'] === 'Jeg har noteret idéen.', json_encode([$r['gemini'], $r['reply']]));

echo "4) it claims again after the nudge\n";
$saved = [];
$r = run([text('Jeg har tilføjet idéen.'), text('Jeg har tilføjet idéen, den er gemt.')], $note, 'dev ide: y');
check('nothing saved, and the reply says so instead of repeating the lie', $saved === [] && str_contains($r['reply'], 'ikke gemt') && str_contains($r['reply'], 'NOT saved') && !str_contains($r['reply'], 'tilføjet'), $r['reply']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
