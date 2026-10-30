<?php

declare(strict_types=1);

/**
 * Follow-ups after the answer: AfterReply (the generic queue), PendingSets + ThermostatConfirm (the watcher that waits for the valves), and set_thermostat
 * registering them. In-memory SQLite, a fake clock, no sleeping.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\PendingSets;
use App\Home\ThermostatConfirm;
use App\Support\AfterReply;
use App\Tools\SetThermostat;
use App\Tools\Tool;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) AfterReply\n";
AfterReply::reset();
AfterReply::collect('k', ['a' => 1], static function (): void {});
check('disabled (no way to release the connection): nothing is queued', AfterReply::hasWork() === false && AfterReply::enabled() === false);
AfterReply::enable(['conversation_id' => 5, 'danish' => true]);
$seen = [];
$runner = static function (array $items, array $ctx) use (&$seen): void { $seen[] = [count($items), $ctx['conversation_id']]; };
AfterReply::collect('k', ['a' => 1], $runner, 100);
AfterReply::collect('k', ['a' => 2], static function (): void { throw new RuntimeException('a later runner is ignored'); }, 400);
AfterReply::collect('other', ['b' => 1], static function (): void { throw new RuntimeException('boom'); }, 50);
check('enabled: items of a key collect together, the request may run the longest job + 60 s', AfterReply::hasWork() && AfterReply::maxSeconds() === 460);
AfterReply::run();
check('one runner call per key with ALL its items and the context; a throwing job does not stop the rest', $seen === [[2, 5]], json_encode($seen));
check('the queue is empty afterwards', AfterReply::hasWork() === false);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE home_state (user_id INT, kind TEXT, payload TEXT, updated_at TEXT, PRIMARY KEY (user_id, kind))');
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'thermostats', 'Stue=200, Badeværelse=201, Soveværelse=202')");
$state   = new HomeState($db);
$pending = new PendingSets($state);

/** A watcher on a fake clock. $reports: valve id => the second (from start) at which it reports the new target; -1 = cannot be read. */
function watcher(PendingSets $p, array $reports, array &$log, int &$clock, ?callable $duringSleep = null): ThermostatConfirm
{
    return new ThermostatConfirm(
        $p, 1,
        static function (int $id) use ($reports, &$clock): ?float {
            $at = $reports[$id] ?? null;
            if ($at === null) { return 17.0; }
            return $at === -1 ? null : ($clock >= $at ? 16.0 : 17.0);
        },
        static function (string $text, ?int $conv) use (&$log): void { $log[] = [$text, $conv]; },
        static function (int $s) use (&$clock, $duringSleep): void { $clock += $s; if ($duringSleep !== null) { $duringSleep(); } },
        static function () use (&$clock): int { return $clock; }
    );
}
function batch(PendingSets $p, array $targets, bool $danish = true, ?int $conv = 9): void
{
    $names = [200 => 'Stue', 201 => 'Badeværelse', 202 => 'Soveværelse'];
    foreach ($targets as $id => $t) { $p->add(1, ['id' => $id, 'name' => $names[$id], 'target' => (float) $t], $conv, $danish); }
}

echo "2) the watcher polls only what is pending, and tells once\n";
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock;
batch($pending, [200 => 16, 201 => 16, 202 => 16]);
$r = watcher($pending, [200 => $t0 + 15, 201 => $t0 + 60, 202 => $t0 + 140], $log, $clock)->run();
check('all three confirm: ONE delivery to the right chat, in Danish, naming them', count($log) === 1 && $log[0] === ['Radiatorerne er nu sat til 16°: Stue, Badeværelse, Soveværelse.', 9], json_encode($log));
check('checks at 20, 50, 90, 150 s — it stops as soon as the last one confirmed', $r['outcome'] === 'confirmed' && $r['checks'] === 4 && $clock - $t0 === 150, json_encode($r));
check('the batch is gone afterwards', $pending->get(1) === null);

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock;
batch($pending, [200 => 16, 201 => 16, 202 => 16], false);
$r = watcher($pending, [200 => $t0 + 10, 201 => $t0 + 10], $log, $clock)->run(480);
check('one valve never confirms: at the deadline ONE message says which (English)', $r['outcome'] === 'deadline' && count($log) === 1 && $log[0][0] === 'Stue, Badeværelse now set to 16°, but Soveværelse has not confirmed yet.', json_encode($log));
check('it gave up inside the time budget', $clock - $t0 <= 480, (string) ($clock - $t0));

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000;
batch($pending, [200 => 16]);
$r = watcher($pending, [], $log, $clock)->run(480);
check('nobody confirms: a "not confirmed yet" message, not silence', $r['outcome'] === 'deadline' && str_contains($log[0][0] ?? '', 'Ingen af radiatorerne har bekræftet 16°'), json_encode($log));

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000;
batch($pending, [200 => 16], false);
$r = watcher($pending, [200 => -1], $log, $clock)->run(100);
check('a valve that cannot be read is simply "not confirmed" (never a crash)', $r['outcome'] === 'deadline' && count($log) === 1);

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000;
batch($pending, [200 => 20]); batch($pending, [200 => 16]);
$r = watcher($pending, [200 => 1001], $log, $clock)->run();
check('the same valve set twice: the last ask counts (16, not 20)', $r['outcome'] === 'confirmed' && str_contains($log[0][0], '16°') && !str_contains($log[0][0], '20'), json_encode($log));

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000;
batch($pending, [200 => 16, 201 => 20]);
$w = new ThermostatConfirm($pending, 1, static fn (int $id): ?float => $id === 200 ? 16.0 : 20.0, static function (string $t, ?int $c) use (&$log): void { $log[] = $t; }, static function (int $s) use (&$clock): void { $clock += $s; }, static fn (): int => $clock);
$w->run();
check('different temperatures in one request are named per valve', ($log[0] ?? '') === 'Radiatorerne er nu sat: Stue 16°, Badeværelse 20°.', $log[0] ?? '');

echo "3) the gateway and the watcher never both announce\n";
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000;
batch($pending, [200 => 16, 201 => 16]);
$r = watcher($pending, [], $log, $clock, static function () use ($pending): void {   // while the watcher sleeps, the gateway reports both valves
    $pending->confirm(1, 200, 16.0);
    $done = $pending->confirm(1, 201, 16.0);
    if ($done !== null) { $GLOBALS['gatewayAnnounced'] = ($GLOBALS['gatewayAnnounced'] ?? 0) + 1; }
})->run();
check('the gateway completed the batch first: it announces (once) and the watcher stays silent', ($GLOBALS['gatewayAnnounced'] ?? 0) === 1 && $r['outcome'] === 'settled elsewhere' && $log === [], json_encode([$GLOBALS['gatewayAnnounced'] ?? 0, $r, $log]));

echo "4) set_thermostat remembers the sent target and only promises when it can follow up\n";
$db->exec('DELETE FROM home_state');
$queued = new class implements Tool {
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array { return ['queued' => true, 'command_id' => 7]; }
};
$tool = new SetThermostat($queued, new UserSettings($db), $state);
AfterReply::reset();
$r = $tool->execute(['thermostat' => 'Stue', 'temperature_c' => 16], 1);
check('nothing can follow up (no AfterReply, no gateway script): sent, no promise, offer to check, nothing stored', $r['set'] === 'sent' && $r['will_notify'] === false && str_contains($r['note'], 'offer to check') && $pending->get(1) === null);
AfterReply::enable(['danish' => true, 'conversation_id' => 12]);
$a = $tool->execute(['thermostat' => 'Stue', 'temperature_c' => 16], 1);
$b = $tool->execute(['thermostat' => 'Badeværelse', 'temperature_c' => 16], 1);
check('with AfterReply: will_notify, and the model is told NOT to offer a check', $a['will_notify'] === true && str_contains($a['note'], 'do not offer to check'));
$jobs = new ReflectionProperty(AfterReply::class, 'jobs'); $jobs->setAccessible(true);
$q = $jobs->getValue();
check('two valves of one turn = ONE watcher job, and ONE pending batch with both', count($q) === 1 && count($q['thermostat_confirm']['items']) === 2 && count($pending->get(1)['items']) === 2 && $pending->get(1)['conversation_id'] === 12 && $pending->get(1)['danish'] === true);
AfterReply::reset();
$db->exec('DELETE FROM home_state');
$state->put(1, 'thermostats', ['rows' => [], 'hook_at' => time() - 60]);
$c = $tool->execute(['thermostat' => 'Soveværelse', 'temperature_c' => 16], 1);
check('gateway script active but no AfterReply: still promises (the gateway will tell), stores it, queues no watcher', $c['will_notify'] === true && $pending->get(1) !== null && AfterReply::hasWork() === false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
