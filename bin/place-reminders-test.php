<?php

declare(strict_types=1);

/**
 * Location-triggered reminders: Support\PlaceArrival (pure), Notify\PlaceReminderRunner and the
 * set_place_reminder tool. In-memory SQLite, no network, pushes captured:
 *   php -d extension=php_pdo_sqlite bin/place-reminders-test.php
 *
 * Coordinates are placeholders on round numbers — never real positions.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\PushSubscriptions;
use App\Data\Reminders;
use App\Notify\PlaceReminderRunner;
use App\Support\PlaceArrival;
use App\Tools\ListReminders;
use App\Tools\SetPlaceReminder;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/place-reminders-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$shop   = ['id' => 1, 'name' => 'Shop', 'type' => 'private', 'shape' => 'circle', 'lat' => 55.0, 'lon' => 12.0, 'radius_m' => 80, 'polygon' => null];
$office = ['id' => 2, 'name' => 'Office', 'type' => 'work', 'shape' => 'circle', 'lat' => 55.1, 'lon' => 12.1, 'radius_m' => 100, 'polygon' => null];
$lab    = ['id' => 3, 'name' => 'Lab', 'type' => 'work', 'shape' => 'circle', 'lat' => 55.2, 'lon' => 12.2, 'radius_m' => 100, 'polygon' => null];
$places = [$shop, $office, $lab];
$pt = static fn (array $at, int $ts = 2000, ?int $acc = 10, ?int $vel = 0): array => ['ts' => $ts, 'lat' => $at[0], 'lon' => $at[1], 'acc' => $acc, 'vel' => $vel];
$rem = static fn (int $id, ?int $placeId, ?string $type, bool $armed = true, int $created = 1000): array =>
    ['id' => $id, 'place_id' => $placeId, 'place_type' => $type, 'armed' => $armed, 'created_ts' => $created];
const AT_SHOP = [55.0, 12.0];
const AT_LAB  = [55.2, 12.2];
const AWAY    = [55.5, 12.5];

echo "1) PlaceArrival\n";
check('arriving at the place fires', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AT_SHOP))['fire'] === [1]);
check('somewhere else does not', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AWAY))['fire'] === []);
check('driving past (fast) does not', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AT_SHOP, 2000, 10, 50))['fire'] === []);
check('a vague fix does not', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AT_SHOP, 2000, 500))['fire'] === []);
check('an old re-sent fix (before the reminder) does not', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AT_SHOP, 500))['fire'] === []);
check('a fix with no speed counts as stopped', PlaceArrival::check([$rem(1, 1, null)], $places, $pt(AT_SHOP, 2000, 10, null))['fire'] === [1]);
check('"at work" fires at ANY work place', PlaceArrival::check([$rem(1, null, 'work')], $places, $pt(AT_LAB))['fire'] === [1]);
check('"at work" ignores a private place', PlaceArrival::check([$rem(1, null, 'work')], $places, $pt(AT_SHOP))['fire'] === []);
check('set while there: no fire while still there', PlaceArrival::check([$rem(1, 1, null, false)], $places, $pt(AT_SHOP)) === ['fire' => [], 'arm' => []]);
check('set while there: leaving arms it', PlaceArrival::check([$rem(1, 1, null, false)], $places, $pt(AWAY))['arm'] === [1]);
check('a deleted place never fires', PlaceArrival::check([$rem(1, 99, null)], $places, $pt(AT_SHOP))['fire'] === []);

echo "\n2) the runner with the database\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (string $s): int => (int) strtotime($s . ' UTC'), 1);
$db->exec("CREATE TABLE reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, remind_at TEXT NULL, place_id INT NULL,
    place_type TEXT NULL, place_label TEXT NULL, place_armed INT NOT NULL DEFAULT 1, text TEXT, status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT)");
$db->exec('CREATE TABLE places (id INTEGER PRIMARY KEY, user_id INT, name TEXT, type TEXT, shape TEXT, lat REAL, lon REAL, radius_m INT, polygon TEXT)');
$db->exec('CREATE TABLE location_points (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device TEXT, recorded_at TEXT, lat REAL, lon REAL, acc INT, alt INT, vel INT, cog INT, batt INT, conn TEXT, trig TEXT)');
$db->exec('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INT, endpoint TEXT, p256dh TEXT, auth TEXT, created_at TEXT)');
foreach ($places as $p) {
    $db->prepare('INSERT INTO places (id, user_id, name, type, shape, lat, lon, radius_m) VALUES (?, 1, ?, ?, ?, ?, ?, ?)')
        ->execute([$p['id'], $p['name'], $p['type'], $p['shape'], $p['lat'], $p['lon'], $p['radius_m']]);
}
$reminders = new Reminders($db);
$sent = [];
$runner = new PlaceReminderRunner($reminders, new Places($db), function (int $u, string $t, string $b, string $url) use (&$sent): void {
    $sent[] = [$u, $t, $b, $url];
});
$now = time();
$rid = $reminders->createAtPlace(1, 1, null, 'Shop', 'Buy batteries');
$timed = $reminders->create(1, gmdate('Y-m-d H:i:s', $now + 3600), 'Call mum');
check('nothing pending for another user', $runner->onPoints(2, [$pt(AT_SHOP, $now + 60)]) === []);
check('away: nothing fires', $runner->onPoints(1, [$pt(AWAY, $now + 60)]) === [] && $sent === []);
check('arriving fires it once', $runner->onPoints(1, [$pt(AT_SHOP, $now + 120)]) === [$rid]);
check('the push says what and where, and opens the reminder', $sent === [[1, 'Shop', 'Buy batteries', '/?card=reminder&rid=' . $rid]], json_encode($sent));
check('a second point there does not fire again', $runner->onPoints(1, [$pt(AT_SHOP, $now + 180)]) === [] && count($sent) === 1);
check('a timed reminder is never fired by location', ($reminders->get(1, $timed)['status'] ?? '') === 'pending');
check('a place reminder is never "due" by time', array_column($reminders->due(), 'id') === []);

$unarmed = $reminders->createAtPlace(1, null, 'work', 'any work place', 'Ask about the invoice', false);
$fired = $runner->onPoints(1, [$pt(AT_LAB, $now + 240), $pt(AWAY, $now + 300), $pt(AT_LAB, $now + 360)]);
check('set while at work: stays quiet, arms on leaving, fires on the next arrival', $fired === [$unarmed], json_encode($fired));

echo "\n3) listing\n";
$c = $reminders->createAtPlace(1, 2, null, 'Office', 'Bring the key');
$list = (new ListReminders($reminders))->execute([], 1);
$whens = array_column($list['reminders'], 'when', 'id');
check('a place reminder lists as "next time at …"', ($whens[$c] ?? '') === 'next time at Office', json_encode($whens));
check('timed ones are still listed with their time', isset($whens[$timed]) && !str_starts_with($whens[$timed], 'next time'));
check('cancel works the same', $reminders->cancel(1, $c) && ($reminders->get(1, $c)['status'] ?? '') === 'cancelled');

echo "\n4) the tool\n";
$tool = new SetPlaceReminder($reminders, new Places($db), new LocationPoints($db), new PushSubscriptions($db));
check('no tracking data yet: refused', isset($tool->execute(['text' => 'x', 'place' => 'Shop'], 1)['error']));
$db->prepare('INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc) VALUES (1, "t", ?, ?, ?, 10)')
    ->execute([gmdate('Y-m-d H:i:s', $now - 60), AT_SHOP[0], AT_SHOP[1]]);
$r = $tool->execute(['text' => 'Buy milk', 'place' => 'shop'], 1);
check('a saved place by name (any case)', ($r['scheduled'] ?? false) === true && $r['where'] === 'Shop', json_encode($r));
check('already there: waits for the next visit, and says so', $r['armed_now'] === false && str_contains($r['note'], 'NEXT arrival'));
$r = $tool->execute(['text' => 'Ask about the invoice', 'place_type' => 'work'], 1);
check('"at work" = any work place, armed (not there now)', ($r['where'] ?? '') === 'any work place' && $r['armed_now'] === true, json_encode($r));
$r = $tool->execute(['text' => 'x', 'place' => 'Gym'], 1);
check('an unknown place is refused with the saved names', isset($r['error']) && str_contains($r['error'], 'Office'));
check('a type nobody has is refused', isset($tool->execute(['text' => 'x', 'place_type' => 'commute'], 1)['error']));
check('a partial name matching one place works', ($tool->execute(['text' => 'x', 'place' => 'offi'], 1)['where'] ?? '') === 'Office');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
