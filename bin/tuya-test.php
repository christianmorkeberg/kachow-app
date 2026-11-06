<?php

declare(strict_types=1);

/** Tuya client (signing, token, parsing), PowerReadings energy maths and the two plug tools. SQLite + a fake transport, no network: php -d extension=pdo_sqlite bin/tuya-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\PowerReadings;
use App\Energy\Tuya;
use App\Tools\GetPlugEnergy;
use App\Tools\GetPlugPower;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) signing and the token\n";
$calls = [];
$transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
    if (str_contains($url, '/v1.0/token')) {
        return [200, json_encode(['success' => true, 'result' => ['access_token' => 'TOK1', 'expire_time' => 7200]])];
    }
    if (str_contains($url, '/associated-users/devices')) {
        return [200, json_encode(['success' => true, 'result' => ['devices' => [
            ['id' => 'plug1', 'name' => 'Freezer', 'online' => true, 'category' => 'cz', 'status' => [['code' => 'switch_1', 'value' => true], ['code' => 'cur_power', 'value' => 1234], ['code' => 'cur_voltage', 'value' => 2305], ['code' => 'cur_current', 'value' => 540], ['code' => 'add_ele', 'value' => 12345]]],
            ['id' => 'lamp1', 'name' => 'Lamp', 'online' => true, 'category' => 'dj', 'status' => [['code' => 'switch_led', 'value' => true]]],
        ], 'has_more' => false]])];
    }
    if (str_contains($url, '/specifications')) {
        return [200, json_encode(['success' => true, 'result' => ['status' => [
            ['code' => 'cur_power', 'values' => '{"unit":"W","scale":1}'], ['code' => 'cur_current', 'values' => '{"unit":"mA","scale":0}'], ['code' => 'add_ele', 'values' => '{"unit":"kWh","scale":3}'],
        ]]])];
    }
    return [200, json_encode(['success' => true, 'result' => [['code' => 'cur_power', 'value' => 500]]])];
};
$t0 = 1_700_000_000_000;
$tuya = new Tuya('ID123', 'SECRET', 'eu', $transport, fn () => $t0);
$tuya->devices();
check('the first call fetches a token (grant_type=1) on the EU host', str_starts_with($calls[0]['url'], 'https://openapi.tuyaeu.com/v1.0/token?grant_type=1'), $calls[0]['url']);
$h0 = $calls[0]['headers'];
$expect = strtoupper(hash_hmac('sha256', 'ID123' . $t0 . '' . "GET\n" . hash('sha256', '') . "\n\n/v1.0/token?grant_type=1", 'SECRET'));
check('the token request is signed client_id + t + nonce + stringToSign (no token yet)', $h0['sign'] === $expect && $h0['client_id'] === 'ID123' && $h0['sign_method'] === 'HMAC-SHA256' && !isset($h0['access_token']));
$h1 = $calls[1]['headers'];
$expect1 = strtoupper(hash_hmac('sha256', 'ID123TOK1' . $t0 . '' . "GET\n" . hash('sha256', '') . "\n\n/v1.0/iot-01/associated-users/devices?size=50", 'SECRET'));
check('a business request adds the token to both the header and the signature', $h1['sign'] === $expect1 && $h1['access_token'] === 'TOK1', json_encode($h1));
$tuya->devices();
check('the token is cached for the next call', count(array_filter($calls, fn ($c) => str_contains($c['url'], '/v1.0/token'))) === 1);

echo "2) devices and readings\n";
$devs = $tuya->devices();
check('devices come with their status codes', count($devs) === 2 && $devs[0]['id'] === 'plug1' && $devs[0]['status']['cur_power'] === 1234 && $devs[0]['online'] === true);
$r = Tuya::reading($devs[0]['status'], $tuya->scales('plug1'));
check('raw values become watts / volts / amps / kWh (scales from the spec)', $r['watts'] === 123.4 && $r['volts'] === 230.5 && $r['amps'] === 0.54 && $r['kwh_total'] === 12.345 && $r['switch'] === true, json_encode($r));
check('without a spec the usual scales apply', Tuya::reading($devs[0]['status'])['watts'] === 123.4);
check('a device with no power codes is not a meter', Tuya::reading($devs[1]['status']) === null);
check('a plug that reports amps in A (unit A) is not divided again', Tuya::reading(['cur_power' => 100, 'cur_current' => 1.5], ['cur_current' => ['scale' => 0, 'unit' => 'A']])['amps'] === 1.5);

echo "3) errors\n";
$tokenFails = new Tuya('ID', 'S', 'eu', fn () => [200, json_encode(['success' => false, 'code' => 1004, 'msg' => 'sign invalid'])]);
try { $tokenFails->devices(); check('a refusal throws with Tuya\'s message', false); } catch (RuntimeException $e) { check('a refusal throws with Tuya\'s message', str_contains($e->getMessage(), 'sign invalid') && str_contains($e->getMessage(), '1004')); }
$n = 0;
$expiring = new Tuya('ID', 'S', 'eu', function (string $m, string $u, array $h, ?string $b) use (&$n): array {
    if (str_contains($u, '/v1.0/token')) { return [200, json_encode(['success' => true, 'result' => ['access_token' => 'T' . (++$n), 'expire_time' => 7200]])]; }
    return $h['access_token'] === 'T1' ? [200, json_encode(['success' => false, 'code' => 1010, 'msg' => 'token invalid'])] : [200, json_encode(['success' => true, 'result' => ['devices' => []]])];
}, fn () => $t0);
check('an expired token (1010) is replaced once and the call retried', $expiring->devices() === [] && $n === 2);
check('not configured: says so instead of calling', !(new Tuya('', '', 'eu'))->isConfigured() && !(new Tuya('a', 'b', 'mars'))->isConfigured());
try { (new Tuya('', '', 'eu'))->devices(); check('…and a call throws', false); } catch (RuntimeException $e) { check('…and a call throws', str_contains($e->getMessage(), 'not configured')); }

echo "4) energy from readings\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE power_readings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, device_id TEXT, device_name TEXT, online INT, switch_on INT, watts REAL, volts REAL, amps REAL, kwh_total REAL, read_at TEXT)');
$store = new PowerReadings($db);
$blank = ['switch' => true, 'watts' => null, 'volts' => 230.0, 'amps' => 1.0, 'kwh_total' => null];
// 100 W flat from 10:00 to 11:00 UTC (13 readings, 5 minutes apart) => 0.1 kWh, entirely in the 10:00 hour
for ($i = 0; $i <= 12; $i++) { $store->add(1, 'plug1', 'Freezer', true, ['watts' => 100.0] + $blank, gmdate('Y-m-d H:i:s', strtotime('2026-11-05 10:00:00 UTC') + $i * 300)); }
$utc = new DateTimeZone('UTC');
$e = $store->energy(1, 'plug1', new DateTimeImmutable('2026-11-05 00:00', $utc), new DateTimeImmutable('2026-11-06 00:00', $utc));
check('100 W for an hour = 0.1 kWh', abs($e['kwh'] - 0.1) < 1e-6 && $e['readings'] === 13 && $e['peak_watts'] === 100.0 && $e['avg_watts'] === 100.0, json_encode($e));
check('covered one hour, all of it in the 10:00 hour', $e['covered_seconds'] === 3600 && array_keys($e['hours']) === [strtotime('2026-11-05 10:00:00 UTC')]);
// a straddling pair: 200 W at 11:55 and 11:55->12:00 crosses the hour border -> split between two hours
$store->add(1, 'plug2', 'Washer', true, ['watts' => 600.0] + $blank, '2026-11-05 11:57:30');
$store->add(1, 'plug2', 'Washer', true, ['watts' => 600.0] + $blank, '2026-11-05 12:02:30');
$e2 = $store->energy(1, 'plug2', new DateTimeImmutable('2026-11-05 00:00', $utc), new DateTimeImmutable('2026-11-06 00:00', $utc));
check('a 5-minute step across an hour border is split between the two hours', count($e2['hours']) === 2 && abs(array_sum($e2['hours']) - 0.05) < 1e-6 && abs($e2['hours'][strtotime('2026-11-05 11:00:00 UTC')] - 0.025) < 1e-6, json_encode($e2['hours']));
$store->add(1, 'plug3', 'Gap', true, ['watts' => 1000.0] + $blank, '2026-11-05 08:00:00');
$store->add(1, 'plug3', 'Gap', true, ['watts' => 1000.0] + $blank, '2026-11-05 12:00:00');
$e3 = $store->energy(1, 'plug3', new DateTimeImmutable('2026-11-05 00:00', $utc), new DateTimeImmutable('2026-11-06 00:00', $utc));
check('a gap of hours is "no data", not 1 kW for four hours', $e3['kwh'] === 0.0 && $e3['covered_seconds'] === 0);
check('another user has no readings', $store->energy(2, 'plug1', new DateTimeImmutable('2026-11-05 00:00', $utc), new DateTimeImmutable('2026-11-06 00:00', $utc))['readings'] === 0);
check('plugs() lists each plug with its latest name', count($store->plugs(1)) === 3 && $store->plugs(1)[0]['name'] === 'Freezer');

echo "5) the tools\n";
putenv('TUYA_USER_ID=1');
$tool = new GetPlugPower($tuya);
$res = $tool->execute([], 1);
check('get_plug_power lists the metering plug only (the lamp is not one)', count($res['plugs']) === 1 && $res['plugs'][0]['name'] === 'Freezer' && $res['plugs'][0]['watts'] === 123.4 && $res['plugs'][0]['on'] === true);
check('a name filter that matches nothing says so', $tool->execute(['plug' => 'washer'], 1)['plugs'] === []);
check('another user gets no access', isset($tool->execute([], 2)['error']));
check('not configured: a plain error, no exception', isset((new GetPlugPower(new Tuya('', '', 'eu')))->execute([], 1)['error']));
$now = new DateTimeImmutable('2026-11-05 14:00', new DateTimeZone('Europe/Copenhagen'));
$energy = new GetPlugEnergy($store, null, $now);
$r1 = $energy->execute(['plug' => 'freezer', 'period' => '2026-11-05'], 1);
check('get_plug_energy: kWh, avg and peak for a date (no price source: cost null)', $r1['plugs'][0]['kwh'] === 0.1 && $r1['plugs'][0]['avg_watts'] === 100.0 && $r1['plugs'][0]['cost_kr'] === null, json_encode($r1));
check('coverage is reported (1 h of a day so far)', $r1['plugs'][0]['covered_share'] > 0 && $r1['plugs'][0]['covered_share'] < 0.2);
check('no readings for that name: an honest error', isset($energy->execute(['plug' => 'nonexistent'], 1)['error']));
check('another user gets no access', isset($energy->execute([], 2)['error']));

echo "6) cost at the real hourly price\n";
$grid = []; for ($h = 0; $h < 24; $h++) { $grid['Price' . ($h + 1)] = $h >= 17 && $h <= 20 ? 0.50 : ($h < 6 ? 0.10 : 0.20); }
$edsTransport = static function (string $url) use ($grid): array {
    if (str_contains($url, 'DayAheadPrices')) {
        $rows = [];
        for ($i = 0; $i < 4 * 4; $i++) {   // 18:00 DK (17:00 UTC) onwards: 1000 DKK/MWh in the first hour, 500 in the second, 300 after
            $rows[] = ['TimeUTC' => (new DateTimeImmutable('2026-10-29T17:00:00', new DateTimeZone('UTC')))->modify('+' . ($i * 15) . ' minutes')->format('Y-m-d\TH:i:s'), 'DayAheadPriceDKK' => [1000.0, 500.0][intdiv($i, 4)] ?? 300.0];
        }
        return [200, json_encode(['records' => $rows])];
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $f = json_decode($q['filter'], true);
    if ($f['GLN_Number'][0] === '5790000705689') {
        return [200, json_encode(['records' => [['ChargeTypeCode' => 'DT_C_01', 'ValidFrom' => '2026-10-01T00:00:00', 'ValidTo' => '2027-01-01T00:00:00'] + $grid]])];
    }
    $flat = static fn (string $c, float $p): array => ['ChargeTypeCode' => $c, 'ValidFrom' => '2026-01-01T00:00:00', 'ValidTo' => '2027-01-01T00:00:00', 'Price1' => $p] + array_combine(array_map(fn ($i) => 'Price' . $i, range(1, 24)), array_fill(0, 24, $p));
    return [200, json_encode(['records' => [$flat('40000', 0.04), $flat('41000', 0.07), $flat('EA-001', 0.01)]])];
};
$tzc = new DateTimeZone('Europe/Copenhagen');
for ($i = 0; $i <= 24; $i++) { $store->add(1, 'plug9', 'Dryer', true, ['watts' => 100.0] + $blank, gmdate('Y-m-d H:i:s', strtotime('2026-10-29 17:00:00 UTC') + $i * 300)); }   // 100 W for 2 hours
$costTool = new GetPlugEnergy($store, new \App\Energy\PowerPrices(new \App\Energy\EnergiDataService($edsTransport)), new DateTimeImmutable('2026-10-29 23:00', $tzc));
$c = $costTool->execute(['plug' => 'dryer', 'period' => '2026-10-29'], 1);
// 18:00 DK = (1.00+0.50+0.11+0.01)*1.25 = 2.025 kr/kWh, 19:00 DK = (0.50+0.50+0.11+0.01)*1.25 = 1.40 kr/kWh; 0.1 kWh each hour
check('cost = Σ kWh in each hour × that hour\'s all-in price (0.1×2.025 + 0.1×1.40 = 0.34 kr)', $c['plugs'][0]['cost_kr'] === 0.34 && $c['plugs'][0]['kwh'] === 0.2 && $c['plugs'][0]['cost_partial'] === false, json_encode($c['plugs'][0]));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
