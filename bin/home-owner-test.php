<?php

declare(strict_types=1);

/**
 * Whose home agent a user talks to (Home\HomeOwner): their own, or a connected person's when that
 * person shares the "home" scope. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/home-owner-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ApiTokens;
use App\Data\Connections;
use App\Home\HomeOwner;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/home-owner-test.php\n");
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
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$db->exec('CREATE TABLE api_tokens (user_id INT, scope TEXT, token TEXT, last_used_at TEXT)');
$db->exec("CREATE TABLE user_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, requester_id INT, addressee_id INT, status TEXT,
    requester_scopes TEXT, addressee_scopes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Owner', 'o@example.test'), (2, 'Partner', 'p@example.test'), (3, 'Stranger', 's@example.test')");

$tokens = new ApiTokens($db);
$conns  = new Connections($db);
$owner  = new HomeOwner($tokens, $conns);
$agent  = fn (int $u) => $db->prepare("INSERT INTO api_tokens (user_id, scope, token) VALUES (?, 'agent', 'tok')")->execute([$u]);
$link   = fn (string $status, string $ownerShares, string $partnerShares) => $db->prepare(
    'INSERT INTO user_connections (requester_id, addressee_id, status, requester_scopes, addressee_scopes) VALUES (1, 2, ?, ?, ?)'
)->execute([$status, $ownerShares, $partnerShares]);

echo "1) a Connections scope exists for it\n";
check('"home" is a valid sharing scope', in_array('home', Connections::APPS, true) && Connections::normalizeScopes(['home', 'nonsense']) === 'home');

echo "2) with no agent anywhere, a user is their own owner\n";
$r = $owner->resolve(2);
check('partner resolves to themselves, not shared', $r['owner_id'] === 2 && $r['shared'] === false);

echo "3) a connected person's agent is NOT used unless they share 'home'\n";
$agent(1);
$link('accepted', 'workouts,calendar', 'workouts');
check('connected + agent but no home scope -> own (nothing)', $owner->resolve(2)['owner_id'] === 2);

echo "4) sharing 'home' lets the partner use the owner's agent\n";
$db->exec("UPDATE user_connections SET requester_scopes = 'workouts,home'");
$r = $owner->resolve(2);
check('partner now rides the owner\'s agent', $r['owner_id'] === 1 && $r['shared'] === true, json_encode($r));
check('knows whose it is (for the answer text)', $r['name'] === 'Owner');
check('the sharing is one-way: the owner still uses their own', $owner->resolve(1) === ['owner_id' => 1, 'shared' => false, 'name' => null]);

echo "5) a person with their own agent always uses it\n";
$agent(2);
check('own agent wins over a shared one', $owner->resolve(2)['owner_id'] === 2 && $owner->resolve(2)['shared'] === false);
$db->exec('DELETE FROM api_tokens WHERE user_id = 2');

echo "6) the grant must be accepted, and revocable\n";
$db->exec("UPDATE user_connections SET status = 'pending'");
check('pending connection grants nothing', $owner->resolve(2)['owner_id'] === 2);
$db->exec("UPDATE user_connections SET status = 'accepted', requester_scopes = 'workouts'");
check('revoking the scope takes it away again', $owner->resolve(2)['owner_id'] === 2);

echo "7) someone not connected gets nothing\n";
check('stranger resolves to themselves', $owner->resolve(3)['owner_id'] === 3);

echo "8) a share from someone WITHOUT an agent leads nowhere\n";
$db->exec('DELETE FROM api_tokens');
$db->exec("UPDATE user_connections SET requester_scopes = 'home'");
check('no agent to ride -> own', $owner->resolve(2)['owner_id'] === 2);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
