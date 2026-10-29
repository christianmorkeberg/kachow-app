<?php

declare(strict_types=1);

/** Electricity price maths + the get_power_prices tool, against canned Energi Data Service answers. No network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Energy\EnergiDataService;
use App\Energy\PowerPrices;
use App\Tools\GetPowerPrices;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

// Radius-like tariff: night 0.10, day 0.20, peak (hours 17-20 -> Price18..Price21) 0.50. Energinet 0.04 + 0.07, tax 0.01.
$grid = []; for ($h = 0; $h < 24; $h++) { $grid['Price' . ($h + 1)] = $h >= 17 && $h <= 20 ? 0.50 : ($h < 6 ? 0.10 : 0.20); }
$priceList = static function (string $url) use ($grid): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $f = json_decode($q['filter'], true);
    if ($f['GLN_Number'][0] === '5790000705689') {
        return [200, json_encode(['records' => [['ChargeTypeCode' => 'DT_C_01', 'ValidFrom' => '2026-10-01T00:00:00', 'ValidTo' => '2027-01-01T00:00:00'] + $grid]])];
    }
    $flat = static fn (string $c, float $p, string $from = '2026-01-01T00:00:00'): array => ['ChargeTypeCode' => $c, 'ValidFrom' => $from, 'ValidTo' => '2027-01-01T00:00:00', 'Price1' => $p];
    return [200, json_encode(['records' => [$flat('40000', 0.04), $flat('41000', 0.07), $flat('EA-001', 0.01), $flat('EA-001', 0.99, '2025-01-01T00:00:00') + ['ValidTo' => '2026-01-01T00:00:00']]])];
};
// Spot: 15-minute rows for 2026-10-29 18:00 .. 2026-10-30 07:00 (DK = UTC+1 after the 25 Oct change). Hour 18 DK costs 1000 DKK/MWh, 19:00 = 500 ... later nights 200.
$spotRows = [];
$start = new DateTimeImmutable('2026-10-29T17:00:00', new DateTimeZone('UTC'));   // 18:00 DK
for ($i = 0; $i < 13 * 4; $i++) {
    $t = $start->modify('+' . ($i * 15) . ' minutes');
    $hourIdx = intdiv($i, 4);
    $price = [1000.0, 500.0][$hourIdx] ?? ($hourIdx >= 5 && $hourIdx <= 8 ? 100.0 : 300.0);
    $spotRows[] = ['TimeUTC' => $t->format('Y-m-d\TH:i:s'), 'DayAheadPriceDKK' => $price];
}
$transport = static function (string $url) use ($priceList, $spotRows): array {
    return str_contains($url, 'DayAheadPrices') ? [200, json_encode(['records' => $spotRows])] : $priceList($url);
};
$tz  = new DateTimeZone('Europe/Copenhagen');
$now = new DateTimeImmutable('2026-10-29 18:20', $tz);

echo "1) all-in price\n";
$svc = new PowerPrices(new EnergiDataService($transport));
$res = $svc->hours($now, new DateTimeImmutable('2026-10-30 07:00', $tz));
check('13 hourly rows from 15-minute slots', count($res['hours']) === 13, (string) count($res['hours']));
// 18:00 DK: spot 1.0 + grid 0.50 + energinet 0.11 + tax 0.01 = 1.62 * 1.25 = 2.025
check('18:00 total = (1.00 + 0.50 + 0.11 + 0.01) x 1.25 = 2.025', abs($res['hours'][0]['total'] - 2.025) < 0.001, (string) $res['hours'][0]['total']);
// the 2025 tax row (0.99) must NOT be used
check('expired tax row ignored', $res['hours'][0]['fees'] < 1.1, (string) $res['hours'][0]['fees']);
check('complete when every tariff was found', $res['incomplete'] === false);

echo "2) cheapest window\n";
$w = PowerPrices::cheapestWindow($res['hours'], 2);
check('2h window = cheap spot + cheap night tariff (00:00)', $w !== null && $res['hours'][$w['index']]['start'] === '2026-10-30T00:00', json_encode($w));

echo "3) tool\n";
$tool = new GetPowerPrices($svc, $now);
$out  = $tool->execute(['window_hours' => 2, 'finish_by' => '07:00'], 1);
check('window found', is_array($out['cheapest_window'] ?? null), json_encode($out['cheapest_window'] ?? null));
check('window starts 00:00', ($out['cheapest_window']['start'] ?? '') === '2026-10-30T00:00', json_encode($out['cheapest_window'] ?? null));
check('card kind + now marker', ($out['_render']['kind'] ?? '') === 'power_prices' && $out['_render']['now_index'] === 0);
check('card window points at the right hour', ($out['_render']['window']['from'] ?? -1) === 6, json_encode($out['_render']['window'] ?? null));
$tight = $tool->execute(['window_hours' => 2, 'finish_by' => '19:00'], 1);
check('no 2h window before 19:00 (only 18:20 left -> hour 18 and 19 do not both end by 19:00)', is_string($tight['cheapest_window'] ?? null), json_encode($tight['cheapest_window'] ?? null));
check('bad day rejected', isset($tool->execute(['day' => 'someday'], 1)['error']));

echo "4) failure paths\n";
$down = new GetPowerPrices(new PowerPrices(new EnergiDataService(static fn (string $u): array => [503, ''])), $now);
check('API down -> friendly error', isset($down->execute([], 1)['error']));
$noTariff = new PowerPrices(new EnergiDataService(static fn (string $u): array => str_contains($u, 'DayAheadPrices') ? [200, json_encode(['records' => $spotRows])] : [200, json_encode(['records' => []])]));
check('missing tariffs flagged incomplete', $noTariff->hours($now, new DateTimeImmutable('2026-10-30 07:00', $tz))['incomplete'] === true);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
