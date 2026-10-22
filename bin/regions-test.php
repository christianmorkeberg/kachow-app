<?php

declare(strict_types=1);

/** Geography for the assistant: bearings, regions as polygons, save_region, where_am_i, and driving INTO a commute place. SQLite, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\LocationPoints;
use App\Data\Places;
use App\Maps\MapDistance;
use App\Support\Geo;
use App\Support\PlaceTransitions;
use App\Tools\SavePlace;
use App\Tools\SaveRegion;
use App\Tools\WhereAmI;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "pdo_sqlite not loaded\n"); exit(2); }
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) bearings and regions\n";
check('compass points: north, east, south-west', Geo::compass(Geo::bearing(55.0, 12.0, 55.5, 12.0)) === 'N' && Geo::compass(Geo::bearing(55.0, 12.0, 55.0, 12.5)) === 'E' && Geo::compass(Geo::bearing(55.0, 12.0, 54.6, 11.4)) === 'SW');
$rel = Geo::relative(55.40, 11.30, 55.64, 12.08);   // a point WEST of the reference
check('relative: distance, direction, and plain east/west/north/south answers', $rel['west_of'] && !$rel['east_of'] && $rel['south_of'] && $rel['compass'] === 'W' || $rel['compass'] === 'SW', json_encode($rel));
check('…and the distance is believable (about 55 km)', $rel['distance_km'] > 45 && $rel['distance_km'] < 65, (string) $rel['distance_km']);
$east = Geo::region('east_of', 55.64, 12.08, 150);
check('east_of is a 4-corner area starting at the reference longitude and reaching east', count($east) === 4 && abs($east[0][1] - 12.08) < 1e-9 && $east[2][1] > 12.08 + 2.0 && $east[2][1] < 12.08 + 3.0);
$p = ['shape' => 'polygon', 'polygon' => $east, 'lat' => 55.64, 'lon' => 12.5, 'radius_m' => null];
check('a point east is inside it, a point west is not, one far beyond the extent is not', Places::contains($p, 55.7, 12.9) && !Places::contains($p, 55.7, 11.5) && !Places::contains($p, 55.7, 16.0));
foreach (['west_of' => [55.7, 11.5, 12.9], 'north_of' => [56.3, 12.0, 54.9], 'south_of' => [55.0, 12.0, 56.3]] as $side => [$inLat, $inLon, $outVal]) {
    $poly = ['shape' => 'polygon', 'polygon' => Geo::region($side, 55.64, 12.08, 150), 'lat' => 0, 'lon' => 0, 'radius_m' => null];
    check($side . ': a point on that side is in, the opposite side is out', Places::contains($poly, $inLat, $inLon) && !Places::contains($poly, $side === 'west_of' ? 55.7 : ($side === 'north_of' ? $outVal : $outVal), $side === 'west_of' ? $outVal : 12.0));
}
check('the extent is clamped; a bad side or a pole is refused', count(Geo::region('east_of', 55.0, 12.0, 5)) === 4 && Geo::region('upwards', 55.0, 12.0) === null && Geo::region('east_of', 85.0, 12.0) === null);

echo "2) the tools\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE places (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, type TEXT DEFAULT 'other', shape TEXT DEFAULT 'circle', lat REAL, lon REAL, radius_m INT, polygon TEXT, created_at TEXT, updated_at TEXT)");
$db->exec('CREATE TABLE location_points (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device TEXT, recorded_at TEXT, lat REAL, lon REAL, acc INT, alt INT, vel INT, batt INT, tid TEXT, conn TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$places = new Places($db);
$points = new LocationPoints($db);
$maps   = new MapDistance('test-key', static fn () => throw new RuntimeException('no network in tests'));
$places->add(1, ['name' => 'Roskilde', 'type' => 'commute', 'lat' => 55.64, 'lon' => 12.08, 'radius_m' => 500]);
$region = new SaveRegion($places, $points, $maps);
$r = $region->execute(['name' => 'East of Roskilde', 'side' => 'east_of', 'reference_place' => 'roskilde', 'extent_km' => 120], 1);
check('save_region makes a polygon place from a saved place as the reference', ($r['saved'] ?? false) === true && $r['place']['shape'] === 'polygon' && $r['place']['type'] === 'commute' && str_contains($r['region'], 'east of Roskilde, out to 120 km'));
check('the same name twice is refused by the places rules', isset($region->execute(['name' => 'east of roskilde', 'side' => 'east_of', 'reference_place' => 'Roskilde'], 1)['error']));
check('coordinates the user gave work as the reference; nonsense does not', ($region->execute(['name' => 'North of A', 'side' => 'north_of', 'lat' => 56.0, 'lon' => 10.0], 1)['saved'] ?? false) === true
    && isset($region->execute(['name' => 'X', 'side' => 'sideways', 'lat' => 56.0, 'lon' => 10.0], 1)['error']) && isset($region->execute(['name' => 'Y', 'side' => 'east_of', 'reference_place' => 'Nowhere'], 1)['error'])
    && isset($region->execute(['name' => 'Z', 'side' => 'east_of'], 1)['error']));
check('another user cannot use my places as a reference', isset($region->execute(['name' => 'Q', 'side' => 'east_of', 'reference_place' => 'Roskilde'], 2)['error']));
$save = new SavePlace($places, $points, $maps);
check('save_place takes coordinates the user gave, and a wide circle (10 km)', ($save->execute(['name' => 'Korsør Motorvej', 'type' => 'commute', 'lat' => 55.33, 'lon' => 11.14, 'radius_m' => 10000], 1)['place']['radius_m'] ?? 0) === 10000
    && isset($save->execute(['name' => 'Huge', 'type' => 'commute', 'lat' => 55.33, 'lon' => 11.14, 'radius_m' => 40000], 1)['error']));

$whereAmI = new WhereAmI($places, $points, $maps);
check('no tracked position yet: it says so', ($whereAmI->execute([], 1)['known'] ?? true) === false);
$db->exec("INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc) VALUES (1, 'phone', '" . gmdate('Y-m-d H:i:s', time() - 120) . "', 55.60, 12.60, 15)");
$w = $whereAmI->execute(['reference_place' => 'Roskilde'], 1);
check('where_am_i: fresh position, inside the east region, and east of Roskilde', $w['known'] && $w['fresh'] && in_array('East of Roskilde', $w['inside'], true) && $w['relative_to']['east_of'] === true && $w['relative_to']['west_of'] === false && $w['age_minutes'] <= 3);
check('…with a distance and a compass direction', $w['relative_to']['distance_km'] > 30 && $w['relative_to']['distance_km'] < 45 && in_array($w['relative_to']['compass'], ['E', 'SE'], true), json_encode($w['relative_to']));
$db->exec("INSERT INTO location_points (user_id, device, recorded_at, lat, lon, acc) VALUES (1, 'phone', '" . gmdate('Y-m-d H:i:s', time() - 7200) . "', 55.60, 12.60, 15)");
$db->exec('DELETE FROM location_points WHERE id = 1');
$w2 = $whereAmI->execute([], 1);
check('an old position is flagged as old, not trusted as fresh', $w2['fresh'] === false && $w2['age_minutes'] >= 119 && str_contains($w2['note'], 'old'));
check('an unknown reference is an error, but the position is still given', isset($whereAmI->execute(['reference_place' => 'Mars'], 1)['error']) && ($whereAmI->execute(['reference_place' => 'Mars'], 1)['known'] ?? false) === true);

echo "3) driving INTO a commute place\n";
$zone = ['id' => 9, 'type' => 'commute', 'shape' => 'circle', 'lat' => 55.0, 'lon' => 12.0, 'radius_m' => 2000, 'polygon' => []];
$pt = static fn (float $lat, float $lon, ?int $vel = 90): array => ['lat' => $lat, 'lon' => $lon, 'acc' => 10, 'vel' => $vel];
$s = PlaceTransitions::step(null, [$zone], $pt(55.1, 12.1), true)['state'];
$a = PlaceTransitions::step($s, [$zone], $pt(55.001, 12.001), true);
check('a fast drive into a commute zone: one point inside is not enough yet', $a['event'] === null && $a['state'] === ['inside' => false, 'streak' => 1]);
$b = PlaceTransitions::step($a['state'], [$zone], $pt(55.0, 12.002), true);
check('…two accurate points inside at 90 km/h = entered', $b['event'] === 'enter' && $b['state'] === ['inside' => true, 'streak' => 0]);
check('without pass-through (a normal place) the same fast drive is still "driving past"', PlaceTransitions::step($s, [$zone], $pt(55.001, 12.001, 90), false) === ['state' => null, 'event' => null]);
check('a single stray point inside that goes away is not an entry', PlaceTransitions::step(PlaceTransitions::step($s, [$zone], $pt(55.001, 12.001), true)['state'], [$zone], $pt(55.1, 12.1), true)['state'] === ['inside' => false, 'streak' => 0]);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
