<?php

declare(strict_types=1);

/**
 * AgentCommands unit tests — the queue between the assistant and the home agent (Pico).
 * In-memory SQLite, no network: php -d extension=php_pdo_sqlite bin/agent-commands-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\AgentCommands;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/agent-commands-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE home_agent_commands (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, device TEXT NOT NULL, method TEXT NOT NULL,
    path TEXT NOT NULL, body TEXT, status TEXT NOT NULL DEFAULT 'pending', http_status INTEGER, response TEXT,
    error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT, done_at TEXT)");
$q = new AgentCommands($db);

echo "1) enqueue validates the request shape\n";
$bad = static function (callable $f): bool {
    try { $f(); } catch (InvalidArgumentException $e) { return true; }
    return false;
};
check('rejects a device with spaces/odd characters', $bad(fn () => $q->enqueue(1, 'hue bridge!', 'GET', '/x')));
check('rejects an unknown method', $bad(fn () => $q->enqueue(1, 'hue', 'PATCH', '/x')));
check('rejects a path without a leading slash', $bad(fn () => $q->enqueue(1, 'hue', 'GET', 'clip/v2')));
check('rejects ".." in a path', $bad(fn () => $q->enqueue(1, 'hue', 'GET', '/clip/../etc')));
check('rejects a path containing a space', $bad(fn () => $q->enqueue(1, 'hue', 'GET', '/clip v2')));
check('rejects an oversized body', $bad(fn () => $q->enqueue(1, 'hue', 'PUT', '/x', ['a' => str_repeat('x', 5000)])));

echo "2) claimNext hands commands out oldest-first, exactly once\n";
$a = $q->enqueue(1, 'HUE', 'put', '/clip/v2/resource/light/1', ['on' => ['on' => true]]);
$b = $q->enqueue(1, 'hue', 'GET', '/clip/v2/resource/room');
$first = $q->claimNext(1);
check('first claim is the oldest command', $first !== null && $first['id'] === $a, json_encode($first));
check('device and method are normalised', $first['device'] === 'hue' && $first['method'] === 'PUT');
check('the JSON body round-trips', $first['body'] === ['on' => ['on' => true]], json_encode($first['body']));
$second = $q->claimNext(1);
check('second claim is the NEXT command, not a repeat', $second !== null && $second['id'] === $b);
check('nothing left to claim', $q->claimNext(1) === null);

echo "3) a user only ever sees their own commands\n";
$other = $q->enqueue(2, 'hue', 'GET', '/x');
check("user 1 can't claim user 2's command", $q->claimNext(1) === null);
$c = $q->claimNext(2);
check('user 2 can', $c !== null && $c['id'] === $other);
check("user 1 can't complete user 2's command", $q->complete(1, $other, 200, '{}', null) === false);

echo "4) complete() records the answer; only a handed-out command accepts one\n";
check('completes a sent command', $q->complete(1, $a, 200, '{"errors":[]}', null) === true);
check('a second answer for the same command is refused', $q->complete(1, $a, 200, 'again', null) === false);
$row = $q->get(1, $a);
check('stored as done with the http status', $row['status'] === 'done' && (int) $row['http_status'] === 200, json_encode($row));
$q->complete(1, $b, null, null, 'bridge unreachable');
check('an error marks it failed', $q->get(1, $b)['status'] === 'failed');
$pending = $q->enqueue(1, 'hue', 'GET', '/never-claimed');
check("can't answer a command that was never handed out", $q->complete(1, $pending, 200, '{}', null) === false);

echo "5) awaitResult returns the agent's answer, or times out and expires the command\n";
$ok = $q->enqueue(1, 'pico', 'GET', '/ping');
$q->claimNext(1); // claims $pending first (older) — hand that one out too, then $ok
$q->claimNext(1);
$q->complete(1, $ok, 200, '{"ok":true}', null);
$r = $q->awaitResult(1, $ok, 1.0);
check('returns done with the response', $r['state'] === 'done' && $r['response'] === '{"ok":true}', json_encode($r));

$lost = $q->enqueue(1, 'hue', 'GET', '/nobody-home');
$t0 = microtime(true);
$r = $q->awaitResult(1, $lost, 0.3);
check('times out when nobody answers', $r['state'] === 'timeout' && (microtime(true) - $t0) < 2.0, json_encode($r));
check('the timed-out command is marked expired', $q->get(1, $lost)['status'] === 'expired');
check("an expired command can't be claimed late by a returning agent", $q->claimNext(1) === null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
