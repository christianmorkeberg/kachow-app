<?php

declare(strict_types=1);

/**
 * PoiNames unit tests — in-memory SQLite + a fake HTTP transport (no real network call):
 *   php -d extension=php_pdo_sqlite bin/poi-names-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Maps\PoiNames;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/poi-names-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

function freshDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE poi_name_cache (
        lat_key REAL NOT NULL, lon_key REAL NOT NULL, name TEXT,
        fetched_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (lat_key, lon_key))");

    return $db;
}

echo "1) a supermarket POI (category=shop, a real name) is suggested\n";
$calls = 0;
$db = freshDb();
$poi = new PoiNames($db, function (string $url) use (&$calls): array {
    $calls++;
    return [200, json_encode(['name' => 'Netto', 'category' => 'shop', 'type' => 'supermarket'])];
});
check('returns "Netto"', $poi->nameNear(55.4, 10.4) === 'Netto');
check('made exactly one HTTP call', $calls === 1, (string) $calls);

echo "2) a bare road/address (no useful category) suggests nothing\n";
$db = freshDb();
$poi = new PoiNames($db, static fn (string $url): array =>
    [200, json_encode(['name' => 'Rugårdsvej', 'category' => 'highway', 'type' => 'residential'])]);
check('returns null for a plain road', $poi->nameNear(55.4, 10.5) === null);

echo "3) a point with no name at all suggests nothing\n";
$db = freshDb();
$poi = new PoiNames($db, static fn (string $url): array => [200, json_encode(['category' => 'place'])]);
check('returns null when Nominatim has no name', $poi->nameNear(55.4, 10.6) === null);

echo "4) a failed/erroring lookup never throws — fails soft to null\n";
$db = freshDb();
$poi = new PoiNames($db, static function (string $url): array { throw new \RuntimeException('network down'); });
$ok     = true;
$result = 'unset';
try {
    $result = $poi->nameNear(55.4, 10.7);
} catch (\Throwable $e) {
    $ok = false;
}
check('no exception escapes nameNear()', $ok);
check('result is null on failure', $result === null, var_export($result, true));

echo "5) the SAME point is only fetched over the network ONCE — the second call hits cache\n";
$calls = 0;
$db = freshDb();
$poi = new PoiNames($db, function (string $url) use (&$calls): array {
    $calls++;
    return [200, json_encode(['name' => 'Føtex', 'category' => 'shop'])];
});
$first  = $poi->nameNear(55.123456, 10.654321);
$second = $poi->nameNear(55.123456, 10.654321); // same point again
check('both calls return the same name', $first === 'Føtex' && $second === 'Føtex');
check('only ONE network call was made (second was a cache hit)', $calls === 1, (string) $calls);

echo "6) a cached \"nothing here\" result (null) is ALSO cached — doesn't re-fetch every time\n";
$calls = 0;
$db = freshDb();
$poi = new PoiNames($db, function (string $url) use (&$calls): array {
    $calls++;
    return [200, json_encode(['category' => 'place'])]; // no name
});
$poi->nameNear(56.0, 11.0);
$poi->nameNear(56.0, 11.0);
check('a cached "no POI" result also short-circuits the network call', $calls === 1, (string) $calls);

echo "7) a nearby point within the same ~11m cell reuses the cache; a clearly distant one doesn't\n";
$calls = 0;
$db = freshDb();
$poi = new PoiNames($db, function (string $url) use (&$calls): array {
    $calls++;
    return [200, json_encode(['name' => 'Lidl', 'category' => 'shop'])];
});
$poi->nameNear(55.00001, 10.00001);
$poi->nameNear(55.00002, 10.00002); // rounds to the same 4-decimal cell
check('two near-identical points share ONE cache cell', $calls === 1, (string) $calls);
$poi->nameNear(56.5, 12.5); // a different cell entirely
check('a genuinely different point triggers its own lookup', $calls === 2, (string) $calls);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
