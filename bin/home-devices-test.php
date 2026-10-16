<?php

declare(strict_types=1);

/**
 * Saved Hue name -> id list: HueEntries, HomeDevices, HueSync (with a stand-in for the Pico) and the
 * merge with conversation memory. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/home-devices-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\HomeDevices;
use App\Home\HueSync;
use App\Support\HueEntries;
use App\Support\ToolMemory;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/home-devices-test.php\n");
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
$db->exec("CREATE TABLE home_devices (
    user_id INTEGER NOT NULL, device TEXT NOT NULL, kind TEXT NOT NULL, rid TEXT NOT NULL, name TEXT NOT NULL,
    grouped_id TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, device, rid))");
$devices = new HomeDevices($db);

$light = static fn (string $id, string $name): array => ['id' => $id, 'type' => 'light', 'metadata' => ['name' => $name]];
$room  = static fn (string $id, string $name, string $gl): array => ['id' => $id, 'type' => 'room', 'metadata' => ['name' => $name],
    'services' => [['rid' => 'dev-1', 'rtype' => 'device'], ['rid' => $gl, 'rtype' => 'grouped_light']]];
$scene = static fn (string $id, string $name): array => ['id' => $id, 'type' => 'scene', 'metadata' => ['name' => $name]];

echo "1) HueEntries reads a Hue list the same way everywhere\n";
$e = HueEntries::fromItems([$light('l1', 'Le klint'), $room('r1', 'Stue', 'gl-1'), ['id' => 'b', 'type' => 'bridge', 'metadata' => ['name' => 'Bridge']], ['id' => 'n', 'type' => 'light']]);
check('keeps lights and rooms, drops unknown types and nameless items', count($e) === 2, json_encode($e));
check('a room carries its grouped_light id', $e[1]['group'] === 'gl-1');
check('line() renders a room with how to switch it', str_contains(HueEntries::line($e[1]), 'switch it via grouped_light gl-1'));

echo "2) HomeDevices saves and replaces one kind at a time\n";
$devices->replaceKind(1, 'hue', 'light', [['name' => 'Le klint', 'id' => 'l1', 'group' => null], ['name' => 'Donut', 'id' => 'l2', 'group' => null]]);
$devices->replaceKind(1, 'hue', 'room', [['name' => 'Stue', 'id' => 'r1', 'group' => 'gl-1']]);
check('everything saved is read back', count($devices->entries(1, 'hue')) === 3);
$devices->replaceKind(1, 'hue', 'light', [['name' => 'Le klint', 'id' => 'l1', 'group' => null]]);
$names = array_column($devices->entries(1, 'hue'), 'name');
check('a light removed in the Hue app disappears here too', !in_array('Donut', $names, true), json_encode($names));
check('replacing lights leaves the rooms alone', in_array('Stue', $names, true));
check("another user sees none of it", $devices->entries(2, 'hue') === []);

echo "3) HueSync::refresh reads every list through the Pico and saves it\n";
$asked = [];
$pico = static function (int $userId, string $path) use (&$asked, $light, $room, $scene): ?array {
    $asked[] = $path;
    return match ($path) {
        '/clip/v2/resource/light' => ['errors' => [], 'data' => [$light('l1', 'Le klint'), $light('l9', 'Ny lampe')]],
        '/clip/v2/resource/room'  => ['errors' => [], 'data' => [$room('r1', 'Stue', 'gl-1')]],
        '/clip/v2/resource/zone'  => ['errors' => [], 'data' => []],
        default                   => null,   // scenes "cut short" on the way back
    };
};
$sync = new HueSync($devices, $pico);
$r = $sync->refresh(1);
check('asked the bridge for lights, rooms, zones and scenes', count($asked) === 4);
check('saved lights, rooms and (empty) zones', $r['saved'] === ['light' => 2, 'room' => 1, 'zone' => 0], json_encode($r));
check('a list that failed is reported, not half-saved', $r['failed'] === ['scene'], json_encode($r));
check('the new light is now saved', in_array('Ny lampe', array_column($devices->entries(1, 'hue'), 'name'), true));

echo "4) HueSync::ingest keeps the list fresh from lists fetched anyway\n";
$sync->ingest(1, 'GET', '/clip/v2/resource/light', 200, ['errors' => [], 'data' => [$light('l1', 'Le klint (omdøbt)')]]);
$names = array_column($devices->entries(1, 'hue'), 'name');
check('a GET of the light list replaces the saved lights', in_array('Le klint (omdøbt)', $names, true) && !in_array('Ny lampe', $names, true), json_encode($names));
$before = count($devices->entries(1, 'hue'));
$sync->ingest(1, 'PUT', '/clip/v2/resource/light', 200, ['errors' => [], 'data' => []]);
$sync->ingest(1, 'GET', '/clip/v2/resource/light/l1', 200, ['errors' => [], 'data' => []]);
$sync->ingest(1, 'GET', '/clip/v2/resource/light', 404, ['errors' => [], 'data' => []]);
$sync->ingest(1, 'GET', '/clip/v2/resource/light', 200, ['errors' => [['description' => 'boom']], 'data' => []]);
check('a PUT, a single resource, a 404 and an error answer are all ignored', count($devices->entries(1, 'hue')) === $before);

echo "5) the saved list and this conversation's lookups merge, newest wins\n";
$base = $devices->entries(1, 'hue');
$conv = [['role' => 'tool', 'tool_name' => 'home_device_call', 'content' => json_encode(['response' => ['data' => [
    ['id' => 'l1-NEW', 'type' => 'light', 'metadata' => ['name' => 'Le klint (omdøbt)']]]]])]];
$block = ToolMemory::homeDevices($conv, $base);
check('saved rooms show up even though this conversation only listed lights', str_contains($block, 'room "Stue" = r1'), $block);
check("this conversation's fresher id beats the saved one", str_contains($block, 'l1-NEW') && !str_contains($block, '= l1' . "\n"), $block);
check('the saved list alone (new conversation) still produces the block', str_contains(ToolMemory::homeDevices([], $base), 'room "Stue"'));
check('nothing saved and nothing looked up = no block', ToolMemory::homeDevices([], []) === '');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
