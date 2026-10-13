<?php

declare(strict_types=1);

/**
 * QuickActions unit tests — pure logic over an in-memory SQLite DB, no network:
 *   php -d extension=php_pdo_sqlite bin/quick-actions-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\QuickActions;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/quick-actions-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

// cached()'s SQL uses MySQL-only date arithmetic (NOW() - INTERVAL ... DAY), which
// isn't valid SQLite syntax (not just a missing function — different grammar), so
// it's left untouched (it's proven, deployed code) and stubbed here instead, to
// test suggestions()'s blending order in isolation from that query.
final class TestableQuickActions extends QuickActions
{
    /** @var array<int, string> */
    private array $fakeCache = [];

    /** @param array<int, string> $suggestions */
    public function setFakeCache(array $suggestions): void
    {
        $this->fakeCache = $suggestions;
    }

    public function cached(int $userId): array
    {
        return $this->fakeCache;
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE conversations (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
$db->exec("CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL)");

$db->exec("INSERT INTO conversations (id, user_id) VALUES (1, 1), (2, 1)");

$insertUser = $db->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (:cid, 'user', :c)");
$insertBot  = $db->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (:cid, 'assistant', :c)");

$tasks = new TestableQuickActions($db);

echo "1) frequent() groups near-duplicate phrasing (punctuation/case vary) as ONE repeat\n";
foreach (["What's on the shopping list?", "what's on the shopping list", "what's on the shopping list"] as $c) {
    $insertUser->execute([':cid' => 1, ':c' => $c]);
}
$insertBot->execute([':cid' => 1, ':c' => "Here's your list: milk, eggs."]);

$frequent = $tasks->frequent(1);
check('exactly one grouped entry', count($frequent) === 1, json_encode($frequent));
check('display text is the newest literal wording', $frequent[0] === "what's on the shopping list", json_encode($frequent));

echo "2) frequent() ignores a single one-off ask (below minCount)\n";
$insertUser->execute([':cid' => 2, ':c' => 'Did it rain in Odense yesterday']);
check('still just the shopping-list repeat', count($tasks->frequent(1)) === 1, json_encode($tasks->frequent(1)));

echo "3) frequent() respects length bounds (too short is dropped)\n";
$insertUser->execute([':cid' => 2, ':c' => 'hi']);
$insertUser->execute([':cid' => 2, ':c' => 'hi']);
check('short "hi" repeat is NOT picked up (minLen)', !in_array('hi', $tasks->frequent(1), true), json_encode($tasks->frequent(1)));

echo "4) suggestions() blends: real repeats FIRST, then cached AI set, then defaults\n";
$tasks->setFakeCache(['Log a run…', 'How much moms do I owe?']);

$blended = $tasks->suggestions(1, 6);
check('frequent chip leads', $blended[0] === "what's on the shopping list", json_encode($blended));
check('cached AI chip follows', in_array('Log a run…', $blended, true), json_encode($blended));
check('a default chip still fills a remaining slot', in_array("What's on my calendar today?", $blended, true), json_encode($blended));

echo "5) suggestions() de-duplicates a cached chip that matches a default (normalized)\n";
$tasks->setFakeCache(['Log a workout']); // no ellipsis — same ask as a DEFAULT
$blended2 = $tasks->suggestions(1, 6);
$count    = count(array_filter($blended2, static fn (string $s): bool => stripos($s, 'log a workout') === 0));
check('"Log a workout" appears only once despite cache+default both having it', $count === 1, json_encode($blended2));

echo "6) a fresh user with no history (and no cache) gets the default set\n";
$tasks->setFakeCache([]);
check('defaults returned for an unseen user', $tasks->suggestions(99) === QuickActions::DEFAULTS, json_encode($tasks->suggestions(99)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
