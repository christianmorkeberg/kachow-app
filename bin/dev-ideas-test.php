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
use App\Tools\NoteDevIdea;
use App\Tools\UpdateDevIdea;
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
    status_at TEXT, details TEXT, priority TEXT NOT NULL DEFAULT 'normal', created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
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

echo "4) details, priority and editing\n";
$d = $ideas->add(1, 'Voice mode as conversation', "Explore the Gemini Live API.\nKeep the current STT/TTS path as a fallback.", 'high');
$e = $ideas->add(1, 'Plate-aware weights');
check('an idea can carry details and a priority', $ideas->findMany([$d])[0]['details'] === "Explore the Gemini Live API.\nKeep the current STT/TTS path as a fallback." && $ideas->findMany([$d])[0]['priority'] === 'high');
check('without them: no details, normal priority', $ideas->findMany([$e])[0]['details'] === null && $ideas->findMany([$e])[0]['priority'] === 'normal');
check('high priority is listed first, then newest first', array_slice(array_column($ideas->listAll(), 'id'), 0, 3) === [$d, $e, $c], json_encode(array_column($ideas->listAll(), 'id')));
$blank = $ideas->add(1, 'Blank details', "  \n ");
check('blank details are stored as nothing', $ideas->findMany([$blank])[0]['details'] === null);
$ideas->delete(1, $blank);
$upd = new UpdateDevIdea($ideas, $users);
$r = $upd->execute(['id' => $e, 'details' => 'Per-side plate breakdown.'], 1);
check('details can be written', $r['updated'] === true && $r['details'] === 'Per-side plate breakdown.' && ($r['_render']['kind'] ?? '') === 'dev_ideas', json_encode($r));
$r = $upd->execute(['id' => $e, 'details' => 'Configurable bar sets.', 'append' => true], 1);
check('and appended as a new paragraph', $r['details'] === "Per-side plate breakdown.\n\nConfigurable bar sets.", json_encode($r['details']));
$r = $upd->execute(['id' => $e, 'details' => 'Replaced.'], 1);
check('or replaced', $r['details'] === 'Replaced.');
$r = $upd->execute(['id' => $e, 'priority' => 'high', 'idea' => 'Plate-aware weight rounding'], 1);
check('priority and headline change together; details untouched', $r['priority'] === 'high' && $r['idea'] === 'Plate-aware weight rounding' && $r['details'] === 'Replaced.');
check('details can be cleared', $upd->execute(['id' => $e, 'details' => ''], 1)['details'] === null);
check('a bad priority is refused', isset($upd->execute(['id' => $e, 'priority' => 'urgent'], 1)['error']));
check('nothing to change is refused', isset($upd->execute(['id' => $e], 1)['error']));
check('an unknown id is reported', ($upd->execute(['id' => 999, 'priority' => 'high'], 1)['updated'] ?? true) === false);
check('editing is developer-only', isset($upd->execute(['id' => $e, 'priority' => 'normal'], 2)['error']) && $ideas->findMany([$e])[0]['priority'] === 'high');
$cardNow = DevIdeas::card($ideas->listAll(), true, 1, 'all');
$dItem = array_values(array_filter($cardNow['items'], fn ($i) => $i['id'] === $d))[0];
check('the card carries details and priority, and lists high first', $dItem['priority'] === 'high' && str_starts_with($dItem['details'], 'Explore')
    && in_array($cardNow['items'][0]['id'], [$d, $e], true));
$noted = (new NoteDevIdea($ideas, $users))->execute(['idea' => 'Thermostat scheduling', 'details' => 'Shelly BLU TRV.', 'priority' => 'high'], 1);
check('note_dev_idea takes details and priority from the developer', $noted['saved'] === true && $ideas->findMany([$noted['id']])[0]['priority'] === 'high' && $ideas->findMany([$noted['id']])[0]['details'] === 'Shelly BLU TRV.');
$noted2 = (new NoteDevIdea($ideas, $users))->execute(['idea' => 'Calendar sync widget', 'priority' => 'high'], 2);
check('anyone else cannot rank their idea', $noted2['saved'] === true && $ideas->findMany([$noted2['id']])[0]['priority'] === 'normal');
$one = (new ListDevIdeas($ideas, $users))->execute(['ids' => [$d]], 1);
check('list with ids returns those ideas WITH details (any status)', $one['count'] === 1 && str_contains($one['ideas'][0]['details'], 'Gemini Live') && $one['ideas'][0]['priority'] === 'high');
$plain = (new ListDevIdeas($ideas, $users))->execute([], 1);
$withD = array_values(array_filter($plain['ideas'], fn ($i) => $i['id'] === $d))[0];
check('the plain list only flags that details exist', ($withD['has_details'] ?? false) === true && !isset($withD['details']));

echo "5) export\n";
$md = DevIdeas::exportMarkdown($ideas->findMany([$d, $a]), '2026-10-19');
check('a heading with the count and date', str_starts_with($md, "# Kachow dev ideas — 2 ideas (2026-10-19)\n"), substr($md, 0, 60));
check('each idea: #id, headline, status, priority', str_contains($md, "## #{$d} · Voice mode as conversation\n_New · high priority · noted ") && str_contains($md, "## #{$a} · Month calendar card\n_In progress · noted "), $md);
check('details follow their idea', str_contains($md, "Explore the Gemini Live API.\nKeep the current STT/TTS path as a fallback.\n"));
check('one idea says "1 idea"', str_starts_with(DevIdeas::exportMarkdown($ideas->findMany([$a]), '2026-10-19'), '# Kachow dev ideas — 1 idea ('));
check('who suggested it is included', str_contains(DevIdeas::exportMarkdown($ideas->findMany([$b]), '2026-10-19'), 'from Alex'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
