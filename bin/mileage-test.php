<?php

declare(strict_types=1);

/**
 * Pure unit tests for Mileage::classifyRows — the tax logic (business vs commuter,
 * per-destination 60-day rule, commute-from-day-1, global 20k km/year tier). No DB,
 * runs anywhere (the local CLI has no PDO driver). Run: php bin/mileage-test.php
 */

require __DIR__ . '/../src/Data/Mileage.php';

use App\Data\Mileage;

$rates = ['rate_high' => 3.79, 'rate_low' => 2.23, 'commute' => 2.23, 'commute_far' => 1.12];

$pass = 0; $fail = 0;
function check(string $label, $got, $want): void
{
    global $pass, $fail;
    $ok = is_float($want) ? abs($got - $want) < 0.01 : $got === $want;
    if ($ok) { $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label — got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"; }
}

/** Builds a trip row. */
function trip(int $id, int $destId, string $date, float $km): array
{
    return ['id' => $id, 'destination_id' => $destId, 'trip_date' => $date, 'km' => $km, 'note' => null];
}
/** N consecutive daily trips to one destination starting at $start. */
function series(int $startId, int $destId, string $start, int $n, float $km): array
{
    $out = []; $ts = strtotime($start);
    for ($i = 0; $i < $n; $i++) {
        $out[] = trip($startId + $i, $destId, date('Y-m-d', $ts + $i * 86400), $km);
    }
    return $out;
}

// ---------------------------------------------------------------------------
echo "1) DTU is commute-from-day-1: never business, even past 60 days\n";
$dests = [7 => ['id' => 7, 'name' => 'DTU', 'type' => Mileage::TYPE_COMMUTE, 'round_trip' => 50.0]];
$rows  = Mileage::classifyRows(series(1, 7, '2026-01-06', 70, 50.0), $dests, $rates);
$biz   = array_filter($rows, fn ($r) => $r['bucket'] === 'business');
check('70 DTU days, business count = 0', count($biz), 0);
// befordringsfradrag on 50 km: (min(50,120)-24)=26 * 2.23 = 57.98
check('DTU day commuter amount = 57.98', $rows[0]['commuter_amount'], 57.98);
check('DTU day business amount = 0', $rows[0]['business_amount'], 0.0);

// ---------------------------------------------------------------------------
echo "2) Business destination: first 60 days business, day 61 flips to commuter\n";
$dests = [3 => ['id' => 3, 'name' => 'Kunde', 'type' => Mileage::TYPE_BUSINESS, 'round_trip' => 40.0]];
$rows  = Mileage::classifyRows(series(1, 3, '2026-01-06', 61, 40.0), $dests, $rates);
$biz   = array_filter($rows, fn ($r) => $r['bucket'] === 'business');
check('60 of 61 days are business', count($biz), 60);
check('day 61 is commuter', $rows[60]['bucket'], 'commuter');
check('business day amount (40km) = 151.60', $rows[0]['business_amount'], 151.60);
// day 61 commuter: (min(40,120)-24)=16 * 2.23 = 35.68
check('day 61 commuter amount = 35.68', $rows[60]['commuter_amount'], 35.68);
check('day 61 rolling_count = 61', $rows[60]['rolling_count'], 61);

// ---------------------------------------------------------------------------
echo "3) Two business destinations have INDEPENDENT 60-day counters\n";
$dests = [
    3 => ['id' => 3, 'name' => 'KundeA', 'type' => Mileage::TYPE_BUSINESS, 'round_trip' => 40.0],
    4 => ['id' => 4, 'name' => 'KundeB', 'type' => Mileage::TYPE_BUSINESS, 'round_trip' => 40.0],
];
$trips = array_merge(
    series(1, 3, '2026-01-06', 61, 40.0),      // KundeA: 61 days
    series(200, 4, '2026-01-06', 3, 40.0)      // KundeB: 3 days, same period
);
$rows = Mileage::classifyRows($trips, $dests, $rates);
$bBiz = array_filter($rows, fn ($r) => $r['destination_id'] === 4 && $r['bucket'] === 'business');
check('KundeB all 3 days business (own counter)', count($bBiz), 3);
$aCom = array_filter($rows, fn ($r) => $r['destination_id'] === 3 && $r['bucket'] === 'commuter');
check('KundeA still has exactly 1 commuter day (its 61st)', count($aCom), 1);

// ---------------------------------------------------------------------------
echo "4) Global 20,000 km/year high-rate tier across business driving\n";
$dests = [3 => ['id' => 3, 'name' => 'Kunde', 'type' => Mileage::TYPE_BUSINESS, 'round_trip' => 0.0]];
$trips = [
    trip(1, 3, '2026-02-01', 15000.0),
    trip(2, 3, '2026-02-02', 4000.0),
    trip(3, 3, '2026-02-03', 3000.0),
];
$rows = Mileage::classifyRows($trips, $dests, $rates);
check('trip1 15000km all high: 15000*3.79', $rows[0]['business_amount'], round(15000 * 3.79, 2));
check('trip2 4000km all high: 4000*3.79', $rows[1]['business_amount'], round(4000 * 3.79, 2));
// prior 19000 → 1000 at high, 2000 at low
check('trip3 3000km split tier', $rows[2]['business_amount'], round(1000 * 3.79 + 2000 * 2.23, 2));

// ---------------------------------------------------------------------------
echo "5) befordringsfradrag two bands (24 free, step at 120)\n";
$dests = [7 => ['id' => 7, 'name' => 'DTU', 'type' => Mileage::TYPE_COMMUTE, 'round_trip' => 0.0]];
$rows  = Mileage::classifyRows([trip(1, 7, '2026-03-01', 200.0)], $dests, $rates);
// (120-24)=96 * 2.23 + (200-120)=80 * 1.12 = 214.08 + 89.6 = 303.68
check('200 km commute = 303.68', $rows[0]['commuter_amount'], 303.68);
$rows2 = Mileage::classifyRows([trip(1, 7, '2026-03-01', 20.0)], $dests, $rates);
check('20 km commute (under 24 free) = 0', $rows2[0]['commuter_amount'], 0.0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
