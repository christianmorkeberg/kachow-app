<?php

declare(strict_types=1);

/**
 * Sharing over connections: the read tools for a connected person's planned workouts, vinyl collection
 * and work hours, behind the one ConnectionAccess gate. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/shared-data-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Connections;
use App\Data\Vinyls;
use App\Data\WorkEvents;
use App\Data\WorkoutPlans;
use App\Tools\GetConnectedVinyls;
use App\Tools\GetConnectedWorkHours;
use App\Tools\GetConnectedWorkoutPlan;
use App\Tools\Tool;
use App\Tools\ToolSelector;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/shared-data-test.php\n");
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
$db->exec('CREATE TABLE user_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, requester_id INT, addressee_id INT, status TEXT,
    requester_scopes TEXT, addressee_scopes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workout_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, plan_date TEXT, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE workout_plan_items (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, exercise TEXT, target_sets INT, target_reps INT,
    target_weight REAL, note TEXT, position INT DEFAULT 0, done INT DEFAULT 0, logged INT DEFAULT 0, done_at TEXT)');
$db->exec('CREATE TABLE vinyls (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, artist TEXT, title TEXT, genre TEXT, style TEXT, year INT,
    discogs_id INT, cover_url TEXT, heard INT DEFAULT 0, rating INT, notes TEXT, added_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT,
    source TEXT DEFAULT "ios_geofence", note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Viewer', 'v@example.test'), (2, 'Owner', 'o@example.test'), (3, 'Stranger', 's@example.test')");

$conns  = new Connections($db);
$plans  = new WorkoutPlans($db, null);
$vinyls = new Vinyls($db);
$events = new WorkEvents($db);
$planTool  = new GetConnectedWorkoutPlan($conns, $plans);
$vinylTool = new GetConnectedVinyls($conns, $vinyls);
$hoursTool = new GetConnectedWorkHours($conns, $events);

/** Viewer is user 1, Owner is user 2; $ownerShares is what the OWNER shares with the viewer. */
$link = static function (string $status, string $ownerShares) use ($db): void {
    $db->exec('DELETE FROM user_connections');
    $db->prepare('INSERT INTO user_connections (requester_id, addressee_id, status, requester_scopes, addressee_scopes) VALUES (2, 1, ?, ?, "")')
        ->execute([$status, $ownerShares]);
};

echo "1) the scopes are defined in one place\n";
check('vinyls and work_hours are valid sharing scopes', Connections::normalizeScopes(['vinyls', 'work_hours', 'nonsense']) === 'vinyls,work_hours');
check('every scope has a description', array_diff(Connections::APPS, array_keys(Connections::SCOPE_HELP)) === []
    && array_diff(array_keys(Connections::SCOPE_HELP), Connections::APPS) === []);
check('the sharing tools are told about every scope', count(array_filter(Connections::APPS, static fn (string $a): bool => !str_contains(Connections::scopeGuide(), $a))) === 0
    && count(array_filter(Connections::APPS, static fn (string $a): bool => !str_contains(Connections::scopeList(), '"' . $a . '"'))) === 0);

echo "\n2) a connected person's planned workouts\n";
$today = date('Y-m-d');
$ownerPlan = $plans->ensurePlanForDate(2, $today, 'Pull day');
$i1 = $plans->addItem($ownerPlan, 'Deadlift', 3, 5, 90.0);
$i2 = $plans->addItem($ownerPlan, 'Strict Press', 3, 5, 32.5);
$db->exec("UPDATE workout_plan_items SET done = 1 WHERE id = {$i1}");
$viewerPlan = $plans->ensurePlanForDate(1, $today, 'Viewer day');
$plans->addItem($viewerPlan, 'Bench', 5, 5, 60.0);

$link('accepted', 'workouts');
$r = $planTool->execute(['person' => 'Owner'], 1);
check('shared: returns THEIR plan, not the viewer\'s', ($r['has_plan'] ?? false) === true && count($r['days']) === 1
    && array_column($r['days'][0]['exercises'], 'name') === ['Deadlift — 3×5 @ 90 kg', 'Strict Press — 3×5 @ 32.5 kg'], json_encode($r['days'] ?? $r));
check('what is ticked and what remains', ($r['days'][0]['exercises'][0]['done'] ?? null) === true && ($r['remaining'] ?? null) === 1);
check('the viewer\'s own plan never leaks in', !str_contains(json_encode($r), 'Bench'));
check('the card is marked as theirs and look-only', ($r['_render']['readonly'] ?? false) === true && ($r['_render']['person']['name'] ?? '') === 'Owner'
    && ($r['_render']['kind'] ?? '') === 'workout_plan');
check('the answer is attributed to them', ($r['person']['name'] ?? '') === 'Owner');

$w = $planTool->execute(['person' => 'Owner', 'period' => 'week', 'date' => $today], 1);
check('the whole week works too', ($w['period'] ?? '') === 'week' && count($w['days']) === 1 && ($w['remaining'] ?? null) === 1);
$none = $planTool->execute(['person' => 'Owner', 'date' => '2001-01-01'], 1);
check('a day without a plan says so', ($none['has_plan'] ?? null) === false && $none['days'] === [] && ($none['remaining'] ?? null) === 0);

$tick = $plans->check(1, $i2, true);
check('the viewer cannot tick off their plan (data layer is user-scoped)', isset($tick['error'])
    && (int) $db->query("SELECT done FROM workout_plan_items WHERE id = {$i2}")->fetchColumn() === 0);

echo "\n3) the gate\n";
$link('accepted', 'vinyls,work_hours,wishlist');
check('another scope does not open plans', isset($planTool->execute(['person' => 'Owner'], 1)['error']));
$link('pending', 'workouts');
check('a pending connection does not', isset($planTool->execute(['person' => 'Owner'], 1)['error']));
check('a stranger gets nothing', isset($planTool->execute(['person' => 'Owner'], 3)['error']));
check('no person named is an error, not a guess', isset($planTool->execute(['person' => ''], 1)['error']));
$link('accepted', 'workouts');
check('revoking takes it away again', !isset($planTool->execute(['person' => 'Owner'], 1)['error'])
    && isset((function () use ($link, $planTool) { $link('accepted', ''); return $planTool->execute(['person' => 'Owner'], 1); })()['error']));

echo "\n4) a connected person's vinyl collection\n";
$vinyls->add(2, ['artist' => 'Miles Davis', 'title' => 'Kind of Blue', 'genre' => 'Jazz', 'style' => 'Modal', 'year' => 1959, 'heard' => true, 'rating' => 5, 'notes' => 'private thought', 'discogs_id' => 99, 'cover_url' => 'http://x/y.jpg']);
$vinyls->add(2, ['artist' => 'Bonobo', 'title' => 'Black Sands', 'genre' => 'Electronic', 'heard' => false]);
$vinyls->add(1, ['artist' => 'Viewer Band', 'title' => 'Mine', 'genre' => 'Rock']);
$link('accepted', 'vinyls');
$v = $vinylTool->execute(['person' => 'Owner'], 1);
check('shared: their records only', ($v['count'] ?? 0) === 2 && !str_contains(json_encode($v), 'Viewer Band'));
check('highest-rated first', ($v['vinyls'][0]['title'] ?? '') === 'Kind of Blue');
check('private notes and plumbing stay out', !str_contains(json_encode($v), 'private thought') && !str_contains(json_encode($v), 'discogs')
    && !str_contains(json_encode($v), 'cover') && !array_key_exists('user_id', $v['vinyls'][0]));
check('genre filter', ($vinylTool->execute(['person' => 'Owner', 'genre' => 'modal'], 1)['count'] ?? 0) === 1);
check('unheard only', ($vinylTool->execute(['person' => 'Owner', 'unheard_only' => true], 1)['vinyls'][0]['title'] ?? '') === 'Black Sands');
check('min rating', ($vinylTool->execute(['person' => 'Owner', 'min_rating' => 5], 1)['count'] ?? 0) === 1);
$link('accepted', 'workouts,work_hours');
check('without the vinyls scope: refused', isset($vinylTool->execute(['person' => 'Owner'], 1)['error']));

echo "\n5) a connected person's work hours\n";
// 2026-09-29 is summer time in Denmark (UTC+2): 09:00–17:00 local = 07:00–15:00 UTC = 8 h.
$ins = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (?, ?, ?, ?)');
$ins->execute([2, 'in',  '2026-09-29 07:00:00', 'Office']);
$ins->execute([2, 'out', '2026-09-29 15:00:00', 'Office']);
$ins->execute([1, 'in',  '2026-09-29 06:00:00', 'Elsewhere']);
$ins->execute([1, 'out', '2026-09-29 08:00:00', 'Elsewhere']);
$ins->execute([2, 'in',  '2026-09-20 07:00:00', 'Office']);   // never clocked out → needs a fix, by THEM
$link('accepted', 'work_hours');
$h = $hoursTool->execute(['person' => 'Owner', 'from' => '2026-09-29', 'to' => '2026-09-29'], 1);
check('shared: their hours, not the viewer\'s', ($h['total_minutes'] ?? 0) === 480 && ($h['session_count'] ?? 0) === 1
    && !str_contains(json_encode($h['sessions'] ?? []), 'Elsewhere'), json_encode($h['total_minutes'] ?? $h));
check('attributed, look-only card', ($h['_render']['readonly'] ?? false) === true && ($h['_render']['person']['name'] ?? '') === 'Owner'
    && ($h['_render']['kind'] ?? '') === 'work_hours');
$fix = $hoursTool->execute(['person' => 'Owner', 'from' => '2026-09-20', 'to' => '2026-09-20'], 1);
check('their forgotten clock-out is theirs to fix: no prompt on the card, no offer to the model',
    ($fix['_render']['needs_fix'] ?? null) === [] && str_contains((string) ($fix['note'] ?? ''), 'do not offer'), json_encode($fix['note'] ?? null));
check('bad dates are refused', isset($hoursTool->execute(['person' => 'Owner', 'from' => 'tomorrow'], 1)['error']));
$link('accepted', 'vinyls,workouts,wishlist,calendar');
check('without the work_hours scope: refused', isset($hoursTool->execute(['person' => 'Owner', 'from' => '2026-09-29', 'to' => '2026-09-29'], 1)['error']));
check('and a stranger gets nothing', isset($hoursTool->execute(['person' => 'Owner'], 3)['error']));

echo "\n6) routing: the new tools are offered for the questions that need them\n";
$decl = static fn (Tool $t): array => ['name' => $t->name(), 'description' => $t->description(), 'parameters' => $t->parameters()];
$all  = [$decl($planTool), $decl($vinylTool), $decl($hoursTool)];
$names = static fn (string $msg): array => array_column(ToolSelector::select($all, $msg), 'name');
check('"can you see Alex\' training plan?" → the plan tool', in_array('get_connected_workout_plan', $names("what is Alex's training plan today?"), true));
check('Danish: "Kan du se Alex\' træningsplanlægning" → the plan tool', in_array('get_connected_workout_plan', $names("Kan du se Alex' træningsplanlægning"), true));
check('"what records does Alex have?" → vinyls', in_array('get_connected_vinyls', $names('what vinyl records does Alex have?'), true));
check('"is Alex still at work?" → work hours', in_array('get_connected_work_hours', $names('is Alex still at work?'), true));
check('Danish: "er Alex på arbejde?" → work hours', in_array('get_connected_work_hours', $names('er Alex på arbejde?'), true));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
