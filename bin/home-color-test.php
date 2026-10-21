<?php

declare(strict_types=1);

/** Hue colours: words / hex / kelvin → what the bridge takes, xy back to a swatch, and undo keeping a light's colour. */
require __DIR__ . '/../vendor/autoload.php';

use App\Support\HueColor;
use App\Support\RoutineSteps;
use App\Tools\HueColorTool;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$red = HueColor::body('red');
check('red is the red corner of the gamut', $red['color']['xy']['x'] > 0.66 && $red['color']['xy']['x'] < 0.71 && $red['color']['xy']['y'] > 0.29 && $red['color']['xy']['y'] < 0.34, json_encode($red));
check('Danish words and hex give the same colour', HueColor::body('rød') === $red && HueColor::body('#FF0000') === $red && HueColor::body('f00') === $red);
$blue = HueColor::body('blå')['color']['xy'];
check('blue is low x, low y', $blue['x'] < 0.2 && $blue['y'] < 0.1, json_encode($blue));
$green = HueColor::body('green')['color']['xy'];
check('green is high y', $green['y'] > 0.5);
check('whites are colour temperatures, warm is a high mirek', HueColor::body('warm white') === ['color_temperature' => ['mirek' => 400]] && HueColor::body('daylight')['color_temperature']['mirek'] < 250);
check('kelvin converts to mirek and is clamped to what a lamp does', HueColor::body('2700K') === ['color_temperature' => ['mirek' => 370]] && HueColor::body('9000k')['color_temperature']['mirek'] === 153 && HueColor::body('1500 K')['color_temperature']['mirek'] === 500);
check('nonsense is not a colour', HueColor::body('') === null && HueColor::body('banana') === null);
check('black is the white point, not a division by zero', HueColor::hexToXy('#000000') === [0.3127, 0.329]);
[$x, $y] = HueColor::hexToXy('#ff8800');
$back = HueColor::xyToHex($x, $y);
check('xy → hex lands close to the colour that made it', preg_match('/^#ff[6-9a][0-9a-f]0[0-9a-f]$/', $back) === 1 || (hexdec(substr($back, 1, 2)) > 230 && hexdec(substr($back, 5, 2)) < 40), $back);
check('a white reads as a light swatch, a candle as amber', HueColor::mirekToHex(153) !== HueColor::mirekToHex(500));
$tool = new HueColorTool();
check('the tool answers with a body, or says what it understands', isset($tool->execute(['color' => 'purple'], 1)['body']['color']) && isset($tool->execute(['color' => 'zzz'], 1)['error']));

echo "undo keeps the colour\n";
$step = ['device' => 'hue', 'method' => 'PUT', 'path' => '/clip/v2/resource/light/aa'];
$colour = RoutineSteps::restoreStep($step, ['response' => ['data' => [['on' => ['on' => true], 'dimming' => ['brightness' => 60.0], 'color_temperature' => ['mirek' => null], 'color' => ['xy' => ['x' => 0.3, 'y' => 0.2]]]]]]);
check('a light in colour mode is put back to its colour', $colour['body'] === ['on' => ['on' => true], 'dimming' => ['brightness' => 60.0], 'color' => ['xy' => ['x' => 0.3, 'y' => 0.2]]]);
$white = RoutineSteps::restoreStep($step, ['response' => ['data' => [['on' => ['on' => true], 'color_temperature' => ['mirek' => 366], 'color' => ['xy' => ['x' => 0.45, 'y' => 0.41]]]]]]);
check('a light in white mode is put back to its white, not an xy', $white['body'] === ['on' => ['on' => true], 'color_temperature' => ['mirek' => 366]]);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
