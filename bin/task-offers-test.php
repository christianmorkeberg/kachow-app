<?php

declare(strict_types=1);

/**
 * Task offers (report #69): a scheduled task that asks "turn them off?" attaches the calls to make on a yes; Run executes them.
 * Validation (house tools only), create / supersede, claim once, skip, and running the stored calls. In-memory SQLite:
 *   php -d extension=pdo_sqlite bin/task-offers-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\TaskOffers;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=pdo_sqlite bin/task-offers-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) what an offer may contain\n";
$ok = TaskOffers::clean([['tool' => 'home_device_call', 'args' => ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/x', 'body' => ['on' => ['on' => false]]]]]);
check('a Hue switch-off call is accepted', isset($ok['calls']) && count($ok['calls']) === 1 && $ok['calls'][0]['tool'] === 'home_device_call');
check('mail / money tools are refused', isset(TaskOffers::clean([['tool' => 'send_email', 'args' => []]])['error']) && isset(TaskOffers::clean([['tool' => 'add_expense', 'args' => []]])['error']));
check('one bad call refuses the whole offer', isset(TaskOffers::clean([['tool' => 'home_device_call', 'args' => []], ['tool' => 'delete_trip', 'args' => []]])['error']));
check('empty, non-list and too long are refused', isset(TaskOffers::clean([])['error']) && isset(TaskOffers::clean('x')['error']) && isset(TaskOffers::clean(['a' => 1])['error'])
    && isset(TaskOffers::clean(array_fill(0, TaskOffers::MAX_CALLS + 1, ['tool' => 'hue_color', 'args' => []]))['error']));

echo "2) storing, answering once\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE task_offers (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, conversation_id INT, task_id INT, question TEXT, calls TEXT,
    status TEXT DEFAULT "pending", result TEXT, created_at TEXT, answered_at TEXT)');
$offers = new TaskOffers($db);
$calls  = $ok['calls'];
$id1 = $offers->create(1, 328, 7, '11 lights are on. Turn them off?', $calls, 1000);
$o   = $offers->get(1, $id1);
check('stored as pending with its calls', $o !== null && $o['status'] === 'pending' && $o['question'] === '11 lights are on. Turn them off?' && $o['calls'] === $calls && $o['conversation_id'] === 328);
check("someone else's offer cannot be read", $offers->get(2, $id1) === null);
$id2 = $offers->create(1, 328, 7, 'newer question', $calls, 2000);
check('a newer offer of the same task supersedes the older one', $offers->get(1, $id1)['status'] === 'superseded' && $offers->get(1, $id2)['status'] === 'pending');
check('the first claim wins, a second tap does not run it again', $offers->claim(1, $id2) === true && $offers->claim(1, $id2) === false && $offers->get(1, $id2)['status'] === 'running');
check("another user cannot claim or skip it", $offers->claim(2, $id2) === false && $offers->skip(2, $id2) === false);
$id3 = $offers->create(1, null, null, 'x', $calls, 3000);
check('skip works once and then it is answered', $offers->skip(1, $id3) === true && $offers->skip(1, $id3) === false && $offers->claim(1, $id3) === false && $offers->get(1, $id3)['status'] === 'skipped');
$offers->finish(1, $id2, 'done', 'Done — 1 of 1 steps went through.');
check('finish records the result', $offers->get(1, $id2)['status'] === 'done' && str_contains((string) $offers->get(1, $id2)['result'], '1 of 1'));

echo "3) running the stored calls\n";
function fakeTool(string $name, callable $fn): Tool
{
    return new class ($name, $fn) implements Tool {
        public function __construct(private string $n, private $fn) {}
        public function name(): string { return $this->n; }
        public function description(): string { return 'x'; }
        public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
        public function execute(array $arguments, int $userId): array { return ($this->fn)($arguments, $userId); }
    };
}
$seen = [];
$registry = new ToolRegistry();
$registry->register(fakeTool('home_device_call', function (array $a, int $u) use (&$seen): array {
    $seen[] = $a['path'] ?? '';
    return ($a['path'] ?? '') === '/bad' ? ['error' => 'the bridge refused'] : ['http_status' => 200];
}));
$registry->register(fakeTool('send_email', fn () => ['sent' => true]));
$three = [['tool' => 'home_device_call', 'args' => ['path' => '/a']], ['tool' => 'home_device_call', 'args' => ['path' => '/bad']], ['tool' => 'home_device_call', 'args' => ['path' => '/c']]];
$res = TaskOffers::run($registry, 1, $three);
check('every call is made, one failing does not stop the rest', $seen === ['/a', '/bad', '/c'] && $res['done'] === 2 && $res['failed'] === 1 && $res['ok'] === false, json_encode($res));
check('the result names the failure', str_contains($res['text'], '2 of 3') && str_contains($res['text'], 'bridge refused'), $res['text']);
$all = TaskOffers::run($registry, 1, [['tool' => 'home_device_call', 'args' => ['path' => '/a']]]);
check('all good → ok and a plain Done line', $all['ok'] === true && str_starts_with($all['text'], 'Done'));
$seen = [];
$sneaky = TaskOffers::run($registry, 1, [['tool' => 'send_email', 'args' => []], ['tool' => 'home_device_call', 'args' => ['path' => '/a']]]);
check('a stored call to a tool outside the house list is NOT made, even if tampered with in the table', $seen === ['/a'] && $sneaky['failed'] === 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
