<?php

declare(strict_types=1);

/** Thermostats: the name=id setting, target limits, and the tools against a fake gateway. No network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;
use App\Support\Thermostats;
use App\Tools\GetThermostats;
use App\Tools\SetThermostat;
use App\Tools\Tool;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) the setting\n";
check('nothing set = one thermostat, id 200', Thermostats::parse('') === [['name' => 'Thermostat', 'id' => 200]] && Thermostats::parse(null) === [['name' => 'Thermostat', 'id' => 200]]);
$all = Thermostats::parse("Living room=200, Bedroom = 201; Køkken:202, junk");
check('name=id pairs, any separator, junk dropped', $all === [['name' => 'Living room', 'id' => 200], ['name' => 'Bedroom', 'id' => 201], ['name' => 'Køkken', 'id' => 202]]);
check('resolve by name, part of a name, id; ambiguous or unknown = null', Thermostats::resolve($all, 'bedroom')['id'] === 201 && Thermostats::resolve($all, 'the living room')['id'] === 200 && Thermostats::resolve($all, '202')['id'] === 202
    && Thermostats::resolve($all, '') === null && Thermostats::resolve($all, 'garage') === null && Thermostats::resolve([$all[0]], '')['id'] === 200);
check('targets round to half degrees and stay in 4–30', Thermostats::target(21.2) === 21.0 && Thermostats::target('21.3') === 21.5 && Thermostats::target(3.7) === null && Thermostats::target(30.5) === null && Thermostats::target('hot') === null && Thermostats::target(4) === 4.0);

echo "2) the tools against a fake gateway\n";
$calls = [];
$home = new class ($calls) implements Tool {
    public array $fail = [];
    public function __construct(public array &$calls) {}
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return ''; }
    public function parameters(): array { return []; }
    public function execute(array $a, int $u): array
    {
        $this->calls[] = $a;
        if ($this->fail !== []) { return $this->fail; }
        if (str_contains($a['path'], 'GetRemoteStatus')) { return ['http_status' => 200, 'response' => ['current_C' => 19.5, 'target_C' => 21, 'pos' => 40, 'errors' => []]]; }
        if (str_contains($a['path'], 'GetConfig')) { $id = (int) substr($a['path'], strrpos($a['path'], '=') + 1); return ['http_status' => 200, 'response' => $id === 200 ? ['id' => 200, 'name' => 'Stue'] : ['id' => $id]]; }
        if (str_contains($a['path'], 'GetStatus')) {
            $id = (int) substr($a['path'], strrpos($a['path'], '=') + 1);
            if ($id > 201) { return ['error' => 'The home agent could not complete that: 500 no such valve']; }
            return ['http_status' => 200, 'response' => ['id' => $id, 'battery' => 100, 'paired' => true]]; }
        return ['http_status' => 200, 'response' => []];
    }
};
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$settings = new UserSettings($db);
$put = static function (string $v) use ($db): void {
    $db->exec('DELETE FROM user_settings');
    $db->prepare("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'thermostats', :v)")->execute([':v' => $v]);
};
$set = new SetThermostat($home, $settings);
$r = $set->execute(['temperature_c' => 21.2], 1);
check('one thermostat: set without naming it, rounded, sent as the valve call', ($r['set'] ?? false) === true && $r['target_c'] === 21.0
    && $calls[0]['device'] === 'shelly' && $calls[0]['method'] === 'POST' && $calls[0]['path'] === '/rpc/BluTrv.Call'
    && $calls[0]['body'] === ['id' => 200, 'method' => 'TRV.SetTarget', 'params' => ['id' => 0, 'target_C' => 21.0]]);
check('an impossible target is refused before any call', isset($set->execute(['temperature_c' => 45], 1)['error']) && count($calls) === 1);
$put('Living room=200, Bedroom=201');
check('two thermostats: it has to be told which', isset($set->execute(['temperature_c' => 20], 1)['error']) && count($calls) === 1);
$r = $set->execute(['thermostat' => 'bedroom', 'temperature_c' => 18], 1);
check('…and uses that valve\'s id', $r['set'] === true && $calls[1]['body']['id'] === 201 && $r['thermostat'] === 'Bedroom');
$home->fail = ['error' => 'No answer from the home agent in 12 seconds.'];
$r = $set->execute(['thermostat' => 'bedroom', 'temperature_c' => 18], 1);
check('a home-agent error is passed on, not called a success', $r['set'] === false && str_contains($r['error'], 'No answer'));
$home->fail = [];
$get = new GetThermostats($home, $settings);
$g = $get->execute([], 1);
check('get reads every thermostat: temperature, target, valve, battery', count($g['thermostats']) === 2 && $g['thermostats'][0] === ['name' => 'Living room', 'id' => 200, 'current_c' => 19.5, 'target_c' => 21, 'valve_open_pct' => 40, 'battery_pct' => 100, 'paired' => true]);
check('get one by name, or say which exist', count($get->execute(['thermostat' => 'bedroom'], 1)['thermostats']) === 1 && str_contains($get->execute(['thermostat' => 'garage'], 1)['error'], 'Living room'));
$put('');
$g = $get->execute([], 1);
check('nothing named: the gateway is asked and both paired valves show, with ids', count($g['thermostats']) === 2 && $g['thermostats'][0]['name'] === 'Stue' && $g['thermostats'][1]['name'] === 'Thermostat 201' && $g['thermostats'][1]['id'] === 201);
$r = $set->execute(['thermostat' => '201', 'temperature_c' => 19], 1);
check('an unnamed valve can be set by its id', $r['set'] === true && $calls[count($calls) - 1]['body']['id'] === 201);
$home->fail = ['error' => 'The home agent (Pico) is offline — last contact 900 seconds ago.'];
check('an unreachable gateway is an error, not "no valves"', isset($get->execute([], 1)['error']));
check('find digs a number out of a nested answer, wherever the firmware puts it', Thermostats::find(['id' => 200, 'status' => ['trv:0' => ['target_C' => 21.5, 'current_C' => 19.0]]], 'current_C') === 19.0 && Thermostats::find(['a' => ['b' => null]], 'b') === null && Thermostats::find('x', 'a') === null);
$home->fail = ['http_status' => 500, 'response' => ['code' => -114, 'message' => 'timeout']];
$g = $get->execute(['thermostat' => '200'], 1);
check('a valve answer with no temperatures shows the raw answer instead of nothing', str_contains($g['thermostats'][0]['note'] ?? '', 'timeout'));
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
