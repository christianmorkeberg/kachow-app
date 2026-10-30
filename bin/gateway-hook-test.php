<?php

declare(strict_types=1);

/** The gateway's report path: HomeState::take, PendingSets, GatewayReport (what api/shelly-hook.php does after the token check), the cache update, and the script text. In-memory SQLite. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\GatewayReport;
use App\Home\GatewayScript;
use App\Home\PendingSets;
use App\Home\ThermostatService;
use App\Tools\Tool;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE home_state (user_id INT, kind TEXT, payload TEXT, updated_at TEXT, PRIMARY KEY (user_id, kind))');
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'thermostats', 'Stue=200, Badeværelse=201')");
$state = new HomeState($db);

echo "1) HomeState::take hands an entry to exactly one caller\n";
$state->put(1, 'x', ['a' => 1]);
$first = $state->take(1, 'x'); $second = $state->take(1, 'x');
check('the first take gets it, the second gets nothing, and it is gone', $first !== null && $first['data']['a'] === 1 && $second === null && $state->get(1, 'x') === null);

echo "2) PendingSets\n";
$p = new PendingSets($state);
$p->add(1, ['id' => 200, 'name' => 'Stue', 'target' => 16.0], 3, true);
$p->add(1, ['id' => 201, 'name' => 'Badeværelse', 'target' => 16.0], 3, true);
check('a batch holds both valves', count($p->get(1)['items']) === 2);
check('a different target is not a confirmation', $p->confirm(1, 200, 17.0) === null && $p->get(1)['items'][0]['confirmed'] === false);
check('half a degree off is not either, 0.2 off is (valves work in half degrees, readings wobble)', $p->confirm(1, 200, 16.5) === null && $p->confirm(1, 200, 16.2) === null && $p->get(1)['items'][0]['confirmed'] === true);
$done = $p->confirm(1, 201, 16.0);
check('the report that completes the batch gets it (once), and it is removed', $done !== null && count($done['items']) === 2 && $p->get(1) === null && $p->confirm(1, 201, 16.0) === null);

echo "3) the cache takes what a valve reported\n";
$home = new class implements Tool {
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array { throw new LogicException('the hook must never ask the home agent'); }
};
$svc = new ThermostatService($home, new UserSettings($db), $state);
check('no gateway call yet: hookActive is false', $svc->hookActive(1) === false);
$told = [];
$rep = new GatewayReport($svc, $p, static function (string $t, ?int $c) use (&$told): void { $told[] = [$t, $c]; });
$p->add(1, ['id' => 200, 'name' => 'Stue', 'target' => 16.0], 3, false);
$p->add(1, ['id' => 201, 'name' => 'Badeværelse', 'target' => 16.0], 3, false);
$r = $rep->accept(1, ['id' => '200', 'target' => '16.00', 'cur' => '21.64', 'pos' => '0', 'ts' => '1793352264', 'rssi' => '-69']);
check('a valid report is accepted', $r['ok'] === true && $r['id'] === 200 && $r['announced'] === false, json_encode($r));
$e = $state->get(1, 'thermostats');
$row = $e['data']['rows'][0] ?? [];
check('its row is in the cache: named from the saved list, with its own report time', ($row['name'] ?? '') === 'Stue' && $row['target_c'] === 16.0 && $row['current_c'] === 21.64 && $row['updated_ts'] === 1793352264 && $row['signal_dbm'] === -69, json_encode($row));
check('the gateway now counts as active', $svc->hookActive(1) === true);
$r2 = $rep->accept(1, ['id' => '201', 'target' => '16', 'cur' => '21.7', 'ts' => '1793352266']);
check('the report that completes the pending batch announces it once, to the chat it came from, with BOTH valves confirmed', $r2['announced'] === true && $told === [['The radiators are now set to 16°: Stue, Badeværelse.', 3]], json_encode($told));
check('both rows are in the cache, ordered by id', array_column($state->get(1, 'thermostats')['data']['rows'], 'id') === [200, 201]);
$rep->accept(1, ['id' => '200', 'target' => '16', 'cur' => '21.5', 'ts' => '1793352570']);
check('a later report just updates the row; nothing pending -> no second announcement', count($told) === 1 && $state->get(1, 'thermostats')['data']['rows'][0]['current_c'] === 21.5);

echo "4) bad reports are refused\n";
check('an unknown valve id', $rep->accept(1, ['id' => '999', 'target' => '16'])['ok'] === false);
check('no temperature at all', $rep->accept(1, ['id' => '200'])['ok'] === false);
check('a nonsense temperature (99 °C target, nothing else) is refused', $rep->accept(1, ['id' => '200', 'target' => '99'])['ok'] === false);
check('text instead of numbers', $rep->accept(1, ['id' => '200', 'target' => 'abc', 'cur' => 'x'])['ok'] === false);

echo "5) the gateway script\n";
$code = GatewayScript::code('https://assistant.example.com/', 'tok123');
check('it carries the hook URL with the token, and the mJS handlers', str_contains($code, 'https://assistant.example.com/api/shelly-hook.php?t=tok123') && str_contains($code, 'Shelly.addStatusHandler') && str_contains($code, 'BluTrv.GetRemoteStatus'));
check('mJS-safe: no arrow functions or template strings', !str_contains($code, '=>') && !str_contains($code, '`'));
check('HTTP goes through Shelly.call("HTTP.GET") — there is no global HTTP object on the gateway (the first version crashed on it)', str_contains($code, 'Shelly.call("HTTP.GET"') && preg_match('/(?<![."\w])HTTP\.GET\(/', $code) !== 1);
check('one call per report (packet_id de-duplication), and a retry when the status is older than the report', str_contains($code, 'packet_id') && str_contains($code, 'tries < 3') && str_contains($code, 'Timer.clear'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
