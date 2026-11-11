<?php

declare(strict_types=1);

/**
 * The Lists card: the overview, rename (empty / clash / ok), and per-item delete / edit with the same connection authorisation as ticking an item — against an in-memory
 * SQLite database. (The auto-clean of ticked items uses MySQL date maths and is not touched here.)
 *   php bin/lists-card-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ShoppingLists;

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
$db->exec('CREATE TABLE user_connections (id INTEGER PRIMARY KEY, requester_id INT, addressee_id INT, status TEXT)');
$db->exec('CREATE TABLE shared_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, connection_id INT, name TEXT, is_default INT DEFAULT 0, created_by INT, grouping TEXT)');
$db->exec('CREATE TABLE shared_list_items (id INTEGER PRIMARY KEY AUTOINCREMENT, list_id INT, item TEXT, checked INT DEFAULT 0, added_by INT, checked_by INT, checked_at TEXT)');
$db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Alex', 'a@example.com'), (2, 'Sam', 's@example.com'), (3, 'Stranger', 'x@example.com')");
$db->exec("INSERT INTO user_connections (id, requester_id, addressee_id, status) VALUES (10, 1, 2, 'accepted')");

$L    = new ShoppingLists($db);
$groc = $L->create(10, 'Groceries', 1, true);
$pack = $L->create(10, 'Packing', 1);
$L->addItem($groc, 'Milk', 1);
$milk = $L->addItem($groc, 'Rice', 2);
$db->exec("UPDATE shared_list_items SET checked = 1 WHERE item = 'Rice'");

echo "1) the overview\n";
$c = $L->overviewCard(10);
check('kind lists, default list first, with counts', $c['kind'] === 'lists' && $c['lists'][0]['name'] === 'Groceries' && $c['lists'][0]['total'] === 2 && $c['lists'][0]['remaining'] === 1 && $c['lists'][1]['total'] === 0, json_encode($c));

echo "2) rename\n";
check('an empty name is refused', $L->rename(10, $pack, '  ') === 'empty');
check('a name another list has is refused — also with other case / spacing', $L->rename(10, $pack, 'groceries') === 'exists' && $L->rename(10, $pack, 'Gro ceries') === 'exists');
check('renaming to its own name (different case) is fine', $L->rename(10, $groc, 'GROCERIES') === true);
check('a new name is saved', $L->rename(10, $pack, 'Holiday') === true && $L->byId(10, $pack)['name'] === 'Holiday');
check('a list of another household cannot be renamed through this connection', $L->byId(99, $pack) === null);

echo "3) items\n";
$ri = $L->renameItemForUser(2, $milk, 'Basmati rice');
check('a party to the connection can edit an item — the card is rebuilt for its list', $ri !== null && $ri['list_id'] === $groc && $db->query("SELECT item FROM shared_list_items WHERE id = $milk")->fetchColumn() === 'Basmati rice');
check('an empty text is refused', $L->renameItemForUser(1, $milk, ' ') === null);
check('a stranger cannot edit or delete it', $L->renameItemForUser(3, $milk, 'x') === null && $L->deleteItemForUser(3, $milk) === null);
check('a party can delete an item', $L->deleteItemForUser(1, $milk) !== null && (int) $db->query("SELECT COUNT(*) FROM shared_list_items WHERE list_id = $groc")->fetchColumn() === 1);

echo "4) deleting a list removes its items\n";
$L->delete(10, $groc);
check('list and items are gone', $L->byId(10, $groc) === null && (int) $db->query('SELECT COUNT(*) FROM shared_list_items')->fetchColumn() <= 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
