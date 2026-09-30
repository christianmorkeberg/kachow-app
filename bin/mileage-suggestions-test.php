<?php

declare(strict_types=1);

/**
 * Pure unit tests for MileageSuggestions::candidates — grouping vehicle drives into one candidate
 * per (date, business/commute place). No DB. Run: php bin/mileage-suggestions-test.php
 */

require __DIR__ . '/../src/Data/MileageSuggestions.php';

use App\Data\MileageSuggestions;

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . json_encode($got) . ", want " . json_encode($want) . "\n"; }
}
function trip(string $date, string $mode, ?string $ft, ?int $fp, ?string $fn, ?string $tt, ?int $tp, ?string $tn, float $km): array
{
    return ['date' => $date, 'mode' => $mode, 'from_type' => $ft, 'from_place_id' => $fp, 'from' => $fn,
            'to_type' => $tt, 'to_place_id' => $tp, 'to' => $tn, 'km' => $km];
}

echo "1) Home → Client → Home (business) = one candidate, GPS km summed\n";
$c = MileageSuggestions::candidates([
    trip('2026-09-29', 'vehicle', 'home', null, 'Home', 'business', 5, 'Client', 32.0),
    trip('2026-09-29', 'vehicle', 'business', 5, 'Client', 'home', null, 'Home', 33.0),
]);
check('one candidate', count($c), 1);
check('keyed date|placeId', array_key_exists('2026-09-29|5', $c), true);
check('gps_km = 65.0 (both legs)', $c['2026-09-29|5']['gps_km'], 65.0);
check('type business', $c['2026-09-29|5']['type'], 'business');

echo "2) walk trips are ignored\n";
$c = MileageSuggestions::candidates([trip('2026-09-29', 'walk', 'home', null, 'Home', 'business', 5, 'Client', 3.0)]);
check('no candidates', count($c), 0);

echo "3) a business end with no saved place (place_id null) is ignored\n";
$c = MileageSuggestions::candidates([trip('2026-09-29', 'vehicle', 'home', null, 'Home', 'business', null, null, 40.0)]);
check('no candidates', count($c), 0);

echo "4) drives that don't touch a business/commute place are ignored\n";
$c = MileageSuggestions::candidates([trip('2026-09-29', 'vehicle', 'home', 1, 'Home', 'private', 2, 'Gym', 12.0)]);
check('no candidates', count($c), 0);

echo "5) commute place (e.g. DTU) is included\n";
$c = MileageSuggestions::candidates([trip('2026-09-28', 'vehicle', 'home', null, 'Home', 'commute', 7, 'DTU', 90.0)]);
check('one candidate', count($c), 1);
check('type commute', $c['2026-09-28|7']['type'], 'commute');

echo "5b) a WORK place (DTU clocked for hours) also yields a drive candidate\n";
$c = MileageSuggestions::candidates([trip('2026-09-28', 'vehicle', 'home', null, 'Home', 'work', 8, 'DTU', 90.0)]);
check('one candidate', count($c), 1);
check('has place 8', array_key_exists('2026-09-28|8', $c), true);

echo "6) two different business places the same day = two candidates\n";
$c = MileageSuggestions::candidates([
    trip('2026-09-29', 'vehicle', 'home', null, 'Home', 'business', 5, 'A', 20.0),
    trip('2026-09-29', 'vehicle', 'business', 5, 'A', 'business', 6, 'B', 10.0),
    trip('2026-09-29', 'vehicle', 'business', 6, 'B', 'home', null, 'Home', 25.0),
]);
check('two candidates', count($c), 2);
check('has place 5', array_key_exists('2026-09-29|5', $c), true);
check('has place 6', array_key_exists('2026-09-29|6', $c), true);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
