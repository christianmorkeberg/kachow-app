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
$db->sqliteCreateFunction('CHAR_LENGTH', static fn (?string $s): int => mb_strlen((string) $s)); // MySQL function
$db->exec("CREATE TABLE conversations (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)");
$db->exec("CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$db->exec("INSERT INTO conversations (id, user_id) VALUES (1, 1), (2, 1)");

$insertUser = $db->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (:cid, 'user', :c)");
$insertBot  = $db->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (:cid, 'assistant', :c)");

$tasks = new TestableQuickActions($db);

echo "1) recentPrompts() drops retry/confirm filler and groups repeats\n";
foreach (["What's on the shopping list?", "what's on the shopping list", 'prøv igen', 'Try again', 'ok', 'Log a run 5 km'] as $c) {
    $insertUser->execute([':cid' => 1, ':c' => $c]);
}
$insertBot->execute([':cid' => 1, ':c' => "Here's your list: milk, eggs."]);
$recent = $tasks->recentPrompts(1, 40);
check('filler is gone', !in_array('prøv igen', $recent, true) && !in_array('Try again', $recent, true) && !in_array('ok', $recent, true), json_encode($recent));
check('repeat appears once, newest wording', count(array_filter($recent, static fn (string $s): bool => stripos($s, 'shopping list') !== false)) === 1, json_encode($recent));
check('assistant replies are not included', !in_array("Here's your list: milk, eggs.", $recent, true), json_encode($recent));

echo "2) recentPrompts() day window\n";
$db->exec("INSERT INTO messages (conversation_id, role, content, created_at) VALUES (1, 'user', 'Old question about vinyl', datetime('now', '-20 days'))");
check('4-day window excludes the 20-day-old message', !in_array('Old question about vinyl', $tasks->recentPrompts(1, 40, 4), true));
check('30-day window includes it', in_array('Old question about vinyl', $tasks->recentPrompts(1, 40, 30), true));

echo "3) suggestions() uses ONE source: the AI set when present\n";
$tasks->setFakeCache(['Log a run…', 'How much moms do I owe?']);
check('only the AI chips, no defaults mixed in', $tasks->suggestions(1, 6) === ['Log a run…', 'How much moms do I owe?'], json_encode($tasks->suggestions(1, 6)));

echo "4) suggestions() de-duplicates and caps\n";
$tasks->setFakeCache(['Log a run', 'log a run…', 'A', 'B', 'C', 'D', 'E']);
check('normalized duplicate dropped, capped at 6', count($tasks->suggestions(1, 6)) === 6 && count(array_filter($tasks->suggestions(1, 6), static fn (string $s): bool => stripos($s, 'log a run') === 0)) === 1, json_encode($tasks->suggestions(1, 6)));

echo "5) no AI set yet -> the seed defaults\n";
$tasks->setFakeCache([]);
check('defaults returned', $tasks->suggestions(1) === QuickActions::DEFAULTS, json_encode($tasks->suggestions(1)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
