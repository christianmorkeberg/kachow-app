<?php

declare(strict_types=1);

/** The Home card's lights: rooms from the bridge's lists, state per light, and the bodies the controls send. */
require __DIR__ . '/../vendor/autoload.php';

use App\Support\HomeCard;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$u = static fn (int $n): string => sprintf('%08x-0000-4000-8000-%012x', $n, $n);
$lights = [
    ['id' => $u(1), 'owner' => ['rid' => 'dev-a'], 'metadata' => ['name' => 'Sofa'], 'on' => ['on' => true], 'dimming' => ['brightness' => 80.4], 'color' => ['xy' => ['x' => 0.675, 'y' => 0.322]]],
    ['id' => $u(2), 'owner' => ['rid' => 'dev-b'], 'metadata' => ['name' => 'Reading'], 'on' => ['on' => false], 'dimming' => ['brightness' => 30.0], 'color_temperature' => ['mirek' => 366]],
    ['id' => $u(3), 'owner' => ['rid' => 'dev-c'], 'metadata' => ['name' => 'Hall'], 'on' => ['on' => true]],
    ['id' => $u(4), 'owner' => ['rid' => 'dev-d'], 'metadata' => ['name' => 'Desk'], 'on' => ['on' => true], 'dimming' => ['brightness' => 50.0]],
];
$rooms = [
    ['id' => 'r2', 'metadata' => ['name' => 'Living room'], 'children' => [['rid' => 'dev-a', 'rtype' => 'device'], ['rid' => 'dev-b', 'rtype' => 'device']], 'services' => [['rid' => $u(90), 'rtype' => 'grouped_light']]],
    ['id' => 'r1', 'metadata' => ['name' => 'Bathroom'], 'children' => [['rid' => 'dev-c', 'rtype' => 'device']], 'services' => [['rid' => $u(91), 'rtype' => 'grouped_light']]],
];
$groups = [['id' => $u(90), 'on' => ['on' => true], 'dimming' => ['brightness' => 55.0]], ['id' => $u(91), 'on' => ['on' => false]]];
$c = HomeCard::lights($lights, $rooms, $groups);
check('rooms come out sorted, with their lights', array_column($c['rooms'], 'name') === ['Bathroom', 'Living room'] && count($c['rooms'][1]['lights']) === 2);
check('a room\'s switch and brightness are the bridge\'s grouped_light', $c['rooms'][1]['on'] === true && $c['rooms'][1]['brightness'] === 55 && $c['rooms'][1]['group_id'] === $u(90) && $c['rooms'][0]['on'] === false);
check('a light that is in no room is listed apart', count($c['other']) === 1 && $c['other'][0]['name'] === 'Desk');
$sofa = $c['rooms'][1]['lights'][0];
check('a colour lamp: on, brightness rounded, can dim and colour, a swatch', $sofa['on'] === true && $sofa['brightness'] === 80 && $sofa['dim'] && $sofa['color'] && !$sofa['ct'] && preg_match('/^#[0-9a-f]{6}$/', (string) $sofa['hex']) === 1);
$reading = $c['rooms'][1]['lights'][1];
check('a white lamp: colour temperature, no colour', $reading['ct'] && !$reading['color'] && $reading['on'] === false);
$hall = $c['rooms'][0]['lights'][0];
check('a plain on/off light has no dimming', !$hall['dim'] && $hall['brightness'] === null);
check('no rooms at all: every light is "other"', count(HomeCard::lights($lights, [], [])['other']) === 4);

check('controls: on / off', HomeCard::body(['on' => true]) === ['on' => ['on' => true]] && HomeCard::body(['on' => 'false']) === ['on' => ['on' => false]]);
check('brightness is clamped, and 0 means off', HomeCard::body(['brightness' => 140])['dimming']['brightness'] === 100.0 && HomeCard::body(['brightness' => 0]) === ['on' => ['on' => false]]);
$col = HomeCard::body(['color' => '#00ff00']);
check('a colour turns the light on and carries xy', $col['on'] === ['on' => true] && isset($col['color']['xy']['x']));
check('a white strip value is a mirek, clamped, and turns the light on', HomeCard::body(['mirek' => 366]) === ['color_temperature' => ['mirek' => 366], 'on' => ['on' => true]] && HomeCard::body(['mirek' => 900])['color_temperature']['mirek'] === 500 && HomeCard::body(['mirek' => 10])['color_temperature']['mirek'] === 153);
check('nothing valid to send, or an unknown colour, is refused', HomeCard::body([]) === null && HomeCard::body(['color' => 'banana']) === null && HomeCard::body(['brightness' => 'x']) === null);
check('only real Hue ids pass', HomeCard::validId($u(1)) && !HomeCard::validId('../x') && !HomeCard::validId('abc'));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
