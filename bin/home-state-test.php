<?php

declare(strict_types=1);

/**
 * The home-state cache and what hangs on it (report #66 speed-up): HomeState, the cached valve list + readings in ThermostatService, get/set_thermostat
 * against it, and the queue's command expiry. In-memory SQLite and a fake home agent that COUNTS the calls it gets — the point is how few reach the Pico.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\AgentCommands;
use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\KnownValves;
use App\Home\ThermostatService;
use App\Support\ToolMemory;
use App\Tools\GetThermostats;
use App\Tools\SetThermostat;
use App\Tools\Tool;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE home_state (user_id INT, kind TEXT, payload TEXT, updated_at TEXT, PRIMARY KEY (user_id, kind))');
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec("CREATE TABLE home_agent_commands (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, device TEXT NOT NULL, method TEXT NOT NULL,
    path TEXT NOT NULL, body TEXT, status TEXT NOT NULL DEFAULT 'pending', http_status INTEGER, response TEXT, error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    sent_at TEXT, done_at TEXT, expires_at TEXT)");

echo "1) HomeState\n";
$st = new HomeState($db);
check('nothing cached yet', $st->get(1, 'lights') === null);
$st->put(1, 'lights', ['rooms' => [['name' => 'Stue']]]);
$e = $st->get(1, 'lights');
check('what was put comes back, with its age', $e !== null && $e['data']['rooms'][0]['name'] === 'Stue' && abs($e['at'] - time()) <= 2);
$st->put(1, 'lights', ['rooms' => []]);
check('put replaces (one row per user+kind)', (int) $db->query('SELECT COUNT(*) FROM home_state')->fetchColumn() === 1 && $st->get(1, 'lights')['data']['rooms'] === []);
check('another user sees nothing', $st->get(2, 'lights') === null);
$st->put(1, 'old', ['x' => 1], time() - 1000);
check('fresh() drops an entry older than the limit, keeps a young one', $st->fresh(1, 'old') === null && $st->fresh(1, 'old', 2000) !== null && $st->fresh(1, 'lights') !== null);
$st->forget(1, 'lights');
check('forget removes it', $st->get(1, 'lights') === null);
$noTable = new HomeState(new PDO('sqlite::memory:'));
$noTable->put(1, 'x', ['a' => 1]);
check('a missing table is never an error (get -> null, put does nothing)', $noTable->get(1, 'x') === null);

// A fake home agent: valves 200 and 201 are paired; every call is counted.
$calls = [];
$home = new class ($calls) implements Tool {
    public function __construct(private array &$calls) {}
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array
    {
        $this->calls[] = $a['method'] . ' ' . $a['path'];
        $path = (string) $a['path'];
        if (preg_match('#id=(\d+)#', $path, $m) === 1) { $id = (int) $m[1]; } else { $id = (int) ($a['body']['id'] ?? 0); }
        $paired = in_array($id, [200, 201], true);
        if (str_contains($path, 'GetStatus')) {
            return $paired ? ['http_status' => 200, 'response' => ['paired' => true, 'battery' => 90, 'rssi' => -60, 'last_updated_ts' => time() - 120]] : ['error' => 'no such valve'];
        }
        if (str_contains($path, 'GetConfig')) { return ['http_status' => 200, 'response' => ['name' => '']]; }
        if (str_contains($path, 'GetRemoteStatus')) {
            return $paired ? ['http_status' => 200, 'response' => ['current_C' => 21.5, 'target_C' => 17.0, 'pos' => 0]] : ['error' => 'no such valve'];
        }
        if (str_contains($path, 'BluTrv.Call')) { return ['http_status' => 200, 'response' => ['ok' => true]]; }

        return ['error' => 'unexpected ' . $path];
    }
};
$settings = new UserSettings($db);
$state    = new HomeState($db);
$svc      = new ThermostatService($home, $settings, $state);

echo "2) the valve list is discovered once, then remembered\n";
$l1 = $svc->list(1); $n1 = count($calls);
check('first list discovers the paired valves (200, 201)', array_column($l1['list'], 'id') === [200, 201] && $n1 > 0, json_encode($l1));
$l2 = $svc->list(1);
check('second list makes NO call to the home agent', count($calls) === $n1 && $l2['list'] === $l1['list'], (string) (count($calls) - $n1));

echo "3) readings come from the cache\n";
$calls = [];
$r1 = $svc->rows(1);
$live = count($calls);
check('first read is live: 2 calls per valve (remote status + status)', $live === 4 && !$r1['cached'] && count($r1['rows']) === 2, (string) $live);
$r2 = $svc->rows(1);
check('the next read makes no call at all', count($calls) === $live && $r2['cached'] === true && count($r2['rows']) === 2);
check('cached rows say how old they are and recompute "heard N minutes ago"', isset($r2['rows'][0]['cached_minutes_ago']) && $r2['rows'][0]['last_heard_minutes_ago'] === 2, json_encode($r2['rows'][0]));
$svc->rows(1, true);
check('fresh=true reads live again', count($calls) === $live * 2);
$state->put(1, 'thermostats', ['rows' => $r1['rows']], time() - 3600);
$calls = [];
check('an entry older than 7 minutes is not served', $svc->rows(1)['cached'] === false && count($calls) === 4);

echo "4) get_thermostats and set_thermostat\n";
$calls = [];
$get = new GetThermostats($home, $settings, $state);
$out = $get->execute([], 1);
check('get_thermostats answers from the cache (no calls) with every valve', count($calls) === 0 && count($out['thermostats'] ?? []) === 2, json_encode($out));
$one = $get->execute(['thermostat' => '201'], 1);
check('one valve by id', count($one['thermostats'] ?? []) === 1 && ($one['thermostats'][0]['id'] ?? 0) === 201);
$set = new SetThermostat($home, $settings, $state);
$res = $set->execute(['thermostat' => '200', 'temperature_c' => 15], 1);
check('set_thermostat reports set', ($res['set'] ?? null) === true && $res['target_c'] === 15.0, json_encode($res));
$after = $get->execute(['thermostat' => '200'], 1)['thermostats'][0] ?? [];
check('and the cached target of that valve already says 15 (the other keeps its own)', ($after['target_c'] ?? null) == 15.0 && (($get->execute(['thermostat' => '201'], 1)['thermostats'][0]['target_c'] ?? null) == 17.0), json_encode($after));

echo "5) commands that nobody waits for expire on their own\n";
$q = new AgentCommands($db);
$a = $q->enqueue(1, 'shelly', 'POST', '/rpc/BluTrv.Call', ['id' => 200], 180);
$exp = $db->query('SELECT expires_at FROM home_agent_commands WHERE id = ' . $a)->fetchColumn();
check('a ttl command carries an expiry ~3 minutes ahead', $exp !== null && abs(strtotime($exp . ' UTC') - (time() + 180)) <= 3, (string) $exp);
$r = $q->awaitResult(1, $a, 0.2, false);
check('a soft wait that times out leaves the command queued', $r['state'] === 'timeout' && $db->query('SELECT status FROM home_agent_commands WHERE id = ' . $a)->fetchColumn() === 'pending');
$claimed = $q->claimNext(1);
check('the Pico can still claim it', $claimed !== null && $claimed['id'] === $a);
$b = $q->enqueue(1, 'shelly', 'POST', '/rpc/BluTrv.Call', ['id' => 201], 180);
$db->exec("UPDATE home_agent_commands SET expires_at = '" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE id = " . $b);
check('a command past its expiry is never handed out (an offline Pico does not run it late)', $q->claimNext(1) === null && $db->query('SELECT status FROM home_agent_commands WHERE id = ' . $b)->fetchColumn() === 'expired');
$c = $q->enqueue(1, 'shelly', 'GET', '/rpc/x');
$q->awaitResult(1, $c, 0.1);
check('the ordinary wait still expires its own command when it gives up', $db->query('SELECT status FROM home_agent_commands WHERE id = ' . $c)->fetchColumn() === 'expired');

echo "6) the radiator list is known without asking, and stays until refreshed\n";
$db->exec('DELETE FROM home_state'); $db->exec('DELETE FROM user_settings');
$calls = [];
check('nothing known yet: an empty list, and no call', KnownValves::get($settings, $state, 1) === [] && count($calls) === 0);
$svc->list(1);
check('after one discovery the valves are known (200, 201) with no call', array_column(KnownValves::get($settings, $state, 1), 'id') === [200, 201]);
$state->put(1, 'valves', ['list' => KnownValves::get($settings, $state, 1)], time() - 90 * 86400);
$calls = [];
check('the list is not aged out — 90 days old, still used, no call', array_column($svc->list(1)['list'], 'id') === [200, 201] && count($calls) === 0);
$state->put(1, 'thermostats', ['rows' => [['id' => 200, 'name' => 'Thermostat 200']]]);
$re = $svc->rediscover(1);
check('rediscover asks the gateway again; same list -> unchanged, cached readings kept', $re['changed'] === false && $state->get(1, 'thermostats') !== null && count($calls) > 0);
// a third valve appears on the gateway
$home3 = new class ($home) implements Tool {
    public function __construct(private Tool $inner) {}
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array
    {
        if (str_contains((string) $a['path'], 'GetStatus?id=202')) { return ['http_status' => 200, 'response' => ['paired' => true, 'battery' => 80, 'rssi' => -70, 'last_updated_ts' => time()]]; }
        return $this->inner->execute($a, $userId);
    }
};
$svc3 = new ThermostatService($home3, $settings, $state);
$re3 = $svc3->rediscover(1);
check('a new valve: the list grows to 3, flagged changed, and the cached readings are dropped', $re3['changed'] === true && count($re3['list']) === 3 && $state->get(1, 'thermostats') === null, json_encode($re3));
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'thermostats', 'Stue=200, Soveværelse=201')");
$calls = [];
check('valves named in the setting win and are never rediscovered', array_column(KnownValves::get($settings, $state, 1), 'name') === ['Stue', 'Soveværelse'] && $svc->rediscover(1)['changed'] === false && count($calls) === 0);

echo "7) the model is told the radiators\n";
$txt = ToolMemory::radiators([['name' => 'Stue', 'id' => 200], ['name' => 'Soveværelse', 'id' => 201]]);
check('the instruction names every radiator with its id and says no lookup is needed for a command', str_contains($txt, 'Stue (id 200)') && str_contains($txt, 'Soveværelse (id 201)') && str_contains($txt, 'do NOT call get_thermostats'));
check('no radiators known -> nothing added', ToolMemory::radiators([]) === '');
check('a chat that used get_thermostats counts as a home conversation', ToolMemory::homeActive([['role' => 'tool', 'tool_name' => 'get_thermostats']]) === true && ToolMemory::homeActive([['role' => 'tool', 'tool_name' => 'get_calendar_events']]) === false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
