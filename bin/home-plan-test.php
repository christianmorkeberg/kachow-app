<?php

declare(strict_types=1);

/** HomePlan: config validation and storage. php -d extension=pdo_sqlite bin/home-plan-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\HomePlan;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$good = ['w' => 1000, 'h' => 800, 'areas' => [['label' => 'Kitchen', 'key' => 'Kit chen!', 'match' => ['KØK', ' kitchen '], 'polygons' => [[[0, 0], [100, 0], [100, 100]], [[1, 1], [2, 2]]]]]];
$c = HomePlan::clean($good);
check('a good config is cleaned: key slug, lower-cased match, short polygons dropped', is_array($c) && $c['areas'][0]['key'] === 'kitchen' && $c['areas'][0]['match'] === ['køk', 'kitchen'] && count($c['areas'][0]['polygons']) === 1, json_encode($c));
check('points are clamped into the image', HomePlan::clean(['w' => 100, 'h' => 100, 'areas' => [['label' => 'A', 'polygons' => [[[-5, 0], [500, 0], [0, 900]]]]]])['areas'][0]['polygons'][0] === [[0.0, 0.0], [100.0, 0.0], [0.0, 100.0]]);
check('no size / no areas / no label / no polygon are refused with a reason', is_string(HomePlan::clean([])) && is_string(HomePlan::clean(['w' => 100, 'h' => 100, 'areas' => []]))
    && is_string(HomePlan::clean(['w' => 100, 'h' => 100, 'areas' => [['polygons' => [[[0, 0], [1, 1], [2, 0]]]]]])) && is_string(HomePlan::clean(['w' => 100, 'h' => 100, 'areas' => [['label' => 'A']]])));
check('a bad point is refused', is_string(HomePlan::clean(['w' => 100, 'h' => 100, 'areas' => [['label' => 'A', 'polygons' => [[[0, 0], ['x', 1], [2, 0]]]]]])));

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$plans = new HomePlan($db);
check('before the migration: nothing, no crash', $plans->get(1) === null && $plans->image(1) === null);
$db->exec('CREATE TABLE home_plans (user_id INTEGER PRIMARY KEY, mime TEXT DEFAULT "image/jpeg", image BLOB, config TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
check('no plan yet', $plans->get(1) === null);
$plans->save(1, null, null, $c);
$g = $plans->get(1);
check('areas saved without an image', $g !== null && $g['has_image'] === false && $g['config']['areas'][0]['label'] === 'Kitchen');
$plans->save(1, "\xFF\xD8fakejpeg", 'image/jpeg', null);
$g = $plans->get(1);
$i = $plans->image(1);
check('the image is added and kept next to the areas', $g['has_image'] === true && $g['config']['w'] == 1000 && $i !== null && $i['bytes'] === "\xFF\xD8fakejpeg" && $i['mime'] === 'image/jpeg');
check('another household sees nothing', $plans->get(2) === null && $plans->image(2) === null);
$plans->remove(1);
check('remove deletes it', $plans->get(1) === null);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
