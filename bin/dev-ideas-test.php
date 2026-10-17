<?php

declare(strict_types=1);

/**
 * Dev ideas with statuses: Data\DevIdeas (setStatus, card) and the set_dev_idea_status /
 * list_dev_ideas tools. In-memory SQLite: php -d extension=php_pdo_sqlite bin/dev-ideas-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\DevIdeas;
use App\Data\Users;
use App\Tools\ListDevIdeas;
use App\Tools\SetDevIdeaStatus;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/dev-ideas-test.php\n");
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
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, is_admin INT DEFAULT 0, role TEXT)");
$db->exec("CREATE TABLE dev_ideas (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, idea TEXT, status TEXT NOT NULL DEFAULT 'new',
    status_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("INSERT INTO users (id, name) VALUES (1, 'Dev'), (2, 'Alex')");

$ideas = new DevIdeas($db);
$a = $ideas->add(1, 'Month calendar card');
$b = $ideas->add(2, 'Dark mode for maps');
$c = $ideas->add(1, 'Version number in the app');

echo "1) statuses\n";
check('new ideas start as new', array_column($ideas->listAll(), 'status') === ['new', 'new', 'new']);
check('set to in_progress', $ideas->setStatus($a, 'in_progress'));
check('setting the same status again still succeeds', $ideas->setStatus($a, 'in_progress'));
check('set to done', $ideas->setStatus($c, 'done'));
check('unknown status refused', !$ideas->setStatus($b, 'shipped'));
check('unknown id refused', !$ideas->setStatus(999, 'done'));
$by = array_column($ideas->listAll(), 'status', 'id');
check('stored correctly', $by[$a] === 'in_progress' && $by[$b] === 'new' && $by[$c] === 'done', json_encode($by));

echo "2) the card\n";
$card = DevIdeas::card($ideas->listAll(), true, 1);
check('kind + editable for the developer', $card['kind'] === 'dev_ideas' && $card['can_edit'] === true);
check('counts per status', $card['counts'] === ['new' => 1, 'in_progress' => 1, 'done' => 1], json_encode($card['counts']));
check('default filter is open', $card['filter'] === 'open');
check('a bad filter falls back to open', DevIdeas::card([], true, 1, 'nonsense')['filter'] === 'open');
$fromAlex = array_values(array_filter($card['items'], fn ($i) => $i['id'] === $b))[0];
check('shows who suggested someone else\'s idea', $fromAlex['from'] === 'Alex');
$mine = array_values(array_filter($card['items'], fn ($i) => $i['id'] === $a))[0];
check('own ideas carry no "from"', $mine['from'] === null);
$theirs = DevIdeas::card($ideas->listForUser(2), false, 2);
check('a non-developer sees only their own, read-only', count($theirs['items']) === 1 && $theirs['can_edit'] === false && !isset($theirs['items'][0]['from']));

echo "3) the tools\n";
$db->exec("UPDATE users SET role = 'admin' WHERE id = 1");
$users = new Users($db);
$set = new SetDevIdeaStatus($ideas, $users);
$r = $set->execute(['ids' => [$b, 999], 'status' => 'done'], 1);
check('developer can mark several; unknown ids reported', $r['updated'] === [$b] && $r['not_found'] === [999], json_encode($r));
check('returns the refreshed card', ($r['_render']['kind'] ?? '') === 'dev_ideas');
check('anyone else is refused', isset($set->execute(['ids' => [$a], 'status' => 'done'], 2)['error']));
check('a bad status is refused', isset($set->execute(['ids' => [$a], 'status' => 'later'], 1)['error']));
$list = (new ListDevIdeas($ideas, $users))->execute([], 1);
check('list defaults to open ideas only', $list['count'] === 1 && $list['ideas'][0]['id'] === $a, json_encode($list['ideas']));
check('list carries all counts and the card', $list['counts']['done'] === 2 && $list['_render']['kind'] === 'dev_ideas');
check('status=all lists everything', (new ListDevIdeas($ideas, $users))->execute(['status' => 'all'], 1)['count'] === 3);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
