<?php

declare(strict_types=1);

/** ToolCallLog + the logging in ToolRegistry::dispatch(), on in-memory SQLite: php -d extension=pdo_sqlite bin/tool-call-log-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Diagnostics\ToolCallLog;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE tool_call_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, source TEXT, task_id INT, conversation_id INT, tool TEXT, ok INT, ms INT, error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

final class FakeTool implements Tool
{
    public function __construct(private string $n, private array $out, private bool $throws = false) {}
    public function name(): string { return $this->n; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $arguments, int $userId): array
    {
        if ($this->throws) { throw new RuntimeException('kaboom'); }
        return $this->out;
    }
}

$reg = new ToolRegistry();
$reg->setCallLog(new ToolCallLog($db));
$reg->register(new FakeTool('get_home_devices', ['on' => 5]));
$reg->register(new FakeTool('notify_me', ['error' => 'push failed']));
$reg->register(new FakeTool('explode', [], true));

echo "1) dispatch logs every call\n";
$reg->setConversation(328);
$reg->dispatch('get_home_devices', ['secret' => 'arg'], 1);
$row = $db->query('SELECT * FROM tool_call_log')->fetch();
check('a chat call: tool, user, source chat, conversation, ok', $row['tool'] === 'get_home_devices' && (int) $row['user_id'] === 1 && $row['source'] === 'chat' && (int) $row['conversation_id'] === 328 && (int) $row['ok'] === 1);
check('arguments are never stored', !str_contains(json_encode($row), 'secret'));
$reg->setContext('scheduled', 77, 400);
$reg->dispatch('get_home_devices', [], 1);
$reg->dispatch('notify_me', [], 1);
$rows = $db->query('SELECT * FROM tool_call_log ORDER BY id')->fetchAll();
check('a scheduled call carries source + task id + conversation', $rows[1]['source'] === 'scheduled' && (int) $rows[1]['task_id'] === 77 && (int) $rows[1]['conversation_id'] === 400);
check('a result with an error key is logged as failed with the message', (int) $rows[2]['ok'] === 0 && $rows[2]['error'] === 'push failed');
check('an explicit source wins over the registry\'s (voice)', ($reg->dispatch('get_home_devices', [], 1, 'voice') && $db->query('SELECT source FROM tool_call_log ORDER BY id DESC LIMIT 1')->fetchColumn() === 'voice'));
try { $reg->dispatch('explode', [], 1); check('the exception propagates', false); } catch (RuntimeException $e) { check('the exception still propagates', $e->getMessage() === 'kaboom'); }
$last = $db->query('SELECT * FROM tool_call_log ORDER BY id DESC LIMIT 1')->fetch();
check('…and is logged as failed with its class', (int) $last['ok'] === 0 && str_contains((string) $last['error'], 'RuntimeException'));
try { $reg->dispatch('nope', [], 1); } catch (InvalidArgumentException) {}
check('an unknown tool is not logged', (int) $db->query("SELECT COUNT(*) FROM tool_call_log WHERE tool = 'nope'")->fetchColumn() === 0);

echo "2) summary, unused, prune\n";
$log = new ToolCallLog($db);
$sum = $log->summary(30);
$db->exec("INSERT INTO tool_call_log (user_id, source, tool, ok, ms) VALUES (1, 'chat', 'get_home_devices', 1, 1), (1, 'chat', 'get_home_devices', 1, 1)");
$sum = $log->summary(30);
check('summary groups by tool and source, busiest first', count($sum) === 5 && $sum[0]['tool'] === 'get_home_devices' && $sum[0]['source'] === 'chat' && $sum[0]['calls'] === 3, json_encode($sum));
check('error count per tool', array_values(array_filter($sum, fn ($r) => $r['tool'] === 'notify_me'))[0]['errors'] === 1);
check('unused lists registered tools never called', $log->unused(['get_home_devices', 'never_called'], 60) === ['never_called']);
$db->exec("INSERT INTO tool_call_log (user_id, source, tool, ok, ms, created_at) VALUES (1, 'chat', 'old', 1, 1, '2020-01-01 00:00:00')");
check('prune removes only old rows', $log->prune(180) === 1 && (int) $db->query("SELECT COUNT(*) FROM tool_call_log WHERE tool = 'old'")->fetchColumn() === 0);

echo "3) a broken log never breaks a tool\n";
$reg2 = new ToolRegistry();
$reg2->setCallLog(new ToolCallLog(new PDO('sqlite::memory:')));   // no table
$reg2->register(new FakeTool('get_home_devices', ['on' => 2]));
check('the tool still returns its result when the table is missing', $reg2->dispatch('get_home_devices', [], 1) === ['on' => 2]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
