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
use App\Home\ValveSets;
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
function watcher(PendingSets $p, array $reports, array &$log, int &$clock, ?callable $duringSleep = null, ?callable $resend = null): ThermostatConfirm
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
        static function () use (&$clock): int { return $clock; },
        $resend
    );
}
function batch(PendingSets $p, array $targets, bool $danish = true, ?int $conv = 9): void
{
    $names = [200 => 'Stue', 201 => 'Badeværelse', 202 => 'Soveværelse'];
    foreach ($targets as $id => $t) { $p->add(1, ['id' => $id, 'name' => $names[$id], 'target' => (float) $t], $conv, $danish, $GLOBALS['clock'] ?? null); }   // the fake clock, so ages line up
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
$resent = [];
$r = watcher($pending, [200 => $t0 + 10, 201 => $t0 + 10], $log, $clock, null, static function (int $id, float $t) use (&$resent): bool { $resent[] = $id; return true; })->run(480);
check('one valve never confirms: at the deadline ONE message says which (English)', $r['outcome'] === 'deadline' && count($log) === 1 && $log[0][0] === 'Stue, Badeværelse now set to 16°, but Soveværelse has not confirmed. I sent it again 2 times. Say “try again” to send it once more.', json_encode($log));
check('it gave up inside the time budget', $clock - $t0 <= 480, (string) ($clock - $t0));
$englishDeadline = $log[0][0] ?? '';

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
check('nothing can follow up (no AfterReply, no gateway script, no watcher): sent, no promise, offer to check — but the batch is stored for the safety net', $r['set'] === 'sent' && $r['will_notify'] === false && str_contains($r['note'], 'offer to check') && $pending->get(1) !== null);
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

echo "5) re-sending, the per-minute safety net and the warning\n";
check('the valve that never confirmed was sent its target again, twice (90 s and 240 s after the send), the others not', $resent === [202, 202], json_encode($resent));
check('…and the deadline message says so', str_contains($englishDeadline, 'I sent it again 2 times'), $englishDeadline);

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock; $resent = [];
batch($pending, [200 => 16]);
$mk = static function (array $reports) use ($pending, &$log, &$clock, &$resent): ThermostatConfirm {
    return watcher($pending, $reports, $log, $clock, null, static function (int $id, float $t) use (&$resent, &$clock): bool { $resent[] = [$id, $t, $clock]; return true; });
};
$clock = $t0 + 30;
check('tick: too early to re-send, still waiting', $mk([200 => $t0 + 9999])->tick()['outcome'] === 'waiting' && $resent === []);
$clock = $t0 + 100;
$mk([200 => $t0 + 9999])->tick();
check('tick at 100 s: the first re-send (same target 16)', count($resent) === 1 && $resent[0][1] === 16.0, json_encode($resent));
check('…recorded on the batch (resends 1, send time reset)', $pending->get(1)['items'][0]['resends'] === 1 && $pending->get(1)['items'][0]['sent_at'] === $t0 + 100);
$clock = $t0 + 130;
$mk([200 => $t0 + 9999])->tick();
check('tick 30 s later: not again yet', count($resent) === 1);
$clock = $t0 + 345;
$mk([200 => $t0 + 9999])->tick();
check('tick 245 s after the second send time: the second (last) re-send', count($resent) === 2);
$clock = $t0 + 400;
$mk([200 => $t0 + 9999])->tick();
check('never a third re-send', count($resent) === 2);
$clock = $t0 + 485;
$r = $mk([200 => $t0 + 9999])->tick();
check('at the deadline the user is told, once, in Danish, with the retry hint', $r['outcome'] === 'deadline' && count($log) === 1 && str_contains($log[0][0], 'Ingen af radiatorerne har bekræftet 16°') && str_contains($log[0][0], 'prøv igen') && str_contains($log[0][0], 'sendt den igen 2 gange'), json_encode($log));
check('a second tick finds nothing (no double message)', $mk([])->tick()['outcome'] === 'none' && count($log) === 1);

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock; $resent = [];
batch($pending, [200 => 16]);
$clock = $t0 + 50;
check('tick sees the valve report the target: confirmed + announced', $mk([200 => $t0 + 10])->tick()['outcome'] === 'confirmed' && count($log) === 1 && str_contains($log[0][0], 'sat til 16°') && $resent === []);

$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock; $resent = [];
batch($pending, [200 => 16]);
$pending->markChecked(1, $t0 + 60);
$clock = $t0 + 100;
check('tick leaves a batch a live watcher touched 40 s ago alone (minIdle 120)', $mk([200 => $t0 + 9999])->tick(120)['outcome'] === 'busy' && $resent === []);
$clock = $t0 + 200;
check('…but takes over once the watcher has been silent for 2 minutes', $mk([200 => $t0 + 9999])->tick(120)['outcome'] === 'waiting');
check('usersWaiting lists whose batches are waiting', $pending->usersWaiting() === [1]);

$db->exec('DELETE FROM home_state');
$state->put(1, 'thermostats', ['rows' => [['id' => 202, 'name' => 'Soveværelse', 'current_c' => 18.0, 'target_c' => 16.0, 'updated_ts' => time() - 50 * 60]]], time());
AfterReply::reset();
$w = $tool->execute(['thermostat' => 'Soveværelse', 'temperature_c' => 17], 1);
check('a valve not heard for 50 minutes: the model is told to warn the user it may not apply', str_contains($w['note'], 'WARNING') && str_contains($w['note'], '50 minutes'), $w['note']);
$state->put(1, 'thermostats', ['rows' => [['id' => 202, 'name' => 'Soveværelse', 'updated_ts' => time() - 2 * 60]]], time());
$ok = $tool->execute(['thermostat' => 'Soveværelse', 'temperature_c' => 17], 1);
check('a valve heard 2 minutes ago: no warning', !str_contains($ok['note'], 'WARNING'));
$state->put(1, 'thermostat_watch_beat', ['at' => time() - 30]);
$db->exec("DELETE FROM home_state WHERE kind = 'pending_sets'");
$beat = $tool->execute(['thermostat' => 'Soveværelse', 'temperature_c' => 18], 1);
check('the per-minute watcher is alive: the follow-up is promised even with no AfterReply and no gateway script', $beat['will_notify'] === true && $pending->get(1) !== null);
$timedOut = new class implements Tool {
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array { return str_contains((string) ($a['path'] ?? ''), 'GetStatus') ? ['response' => ['paired' => true]] : ['error' => 'ETIMEDOUT']; }
};
$db->exec("DELETE FROM home_state WHERE kind = 'pending_sets'");
$tt = (new SetThermostat($timedOut, new UserSettings($db), $state))->execute(['thermostat' => 'Stue', 'temperature_c' => 16], 1);
check('delivered but timed out at the gateway: sent AND followed up (it used to be forgotten)', $tt['set'] === 'sent' && $tt['will_notify'] === true && $pending->get(1) !== null && $pending->get(1)['items'][0]['name'] === 'Stue', json_encode($tt));

echo "6) per-valve status for the Home card (sending / confirmed / failed)\n";
$valves = new ValveSets($state);
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock; $resent = [];
batch($pending, [200 => 16, 201 => 16]);
$st = $valves->all(1, $t0 + 5);
check('a sent target shows as "sending" with its target', ($st[200]['state'] ?? '') === 'sending' && ($st[200]['target'] ?? 0) === 16.0 && ($st[201]['state'] ?? '') === 'sending', json_encode($st));
$pending->confirm(1, 200, 16.0);
$st = $valves->all(1, $t0 + 30);
check('a valve that reports the target becomes "confirmed", the other stays "sending"', ($st[200]['state'] ?? '') === 'confirmed' && ($st[201]['state'] ?? '') === 'sending', json_encode($st));
$clock = $t0 + 485;
$mk([201 => $t0 + 9999])->tick();
$st = $valves->all(1, $clock);
check('at the deadline the valve that never confirmed becomes "failed" (the confirmed one stays confirmed)', ($st[201]['state'] ?? '') === 'failed' && ($st[201]['target'] ?? 0) === 16.0 && ($st[200]['state'] ?? '') === 'confirmed', json_encode($st));
batch($pending, [201 => 17]);
$st = $valves->all(1, $clock + 1);
check('retrying (sending again) replaces the failure with "sending" and the new target', ($st[201]['state'] ?? '') === 'sending' && ($st[201]['target'] ?? 0) === 17.0);
$db->exec('DELETE FROM home_state'); $clock = 1000;
batch($pending, [200 => 16]);
check('a "sending" whose watchers all died is shown as failed after the deadline + 1 min — never "sending" forever', ($valves->all(1, 1000 + 480 + 61)[200]['state'] ?? '') === 'failed' && ($valves->all(1, 1000 + 400)[200]['state'] ?? '') === 'sending');
$valves->mark(1, 202, 'confirmed', 16.0, 1000);
check('a confirmed result falls away after 10 minutes, a failure after an hour', !isset($valves->all(1, 1000 + 601)[202]));
$valves->mark(1, 202, 'failed', 16.0, 1000);
check('…a failure is still there after 30 minutes and gone after 61', isset($valves->all(1, 1000 + 1800)[202]) && !isset($valves->all(1, 1000 + 3601)[202]));
$valves->mark(1, 203, 'bogus', 16.0, 1000);
check('an unknown state is never stored', !isset($valves->all(1, 1001)[203]));


echo "voice) a batch asked by voice is not announced again when everything arrived — but a failure always is\n";
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock;
foreach ([200, 201] as $id) { $pending->add(1, ['id' => $id, 'name' => $id === 200 ? 'Stue' : 'Badeværelse', 'target' => 16.0], null, false, $clock, true); }
watcher($pending, [200 => $t0 + 15, 201 => $t0 + 30], $log, $clock)->run();
check('all confirmed by voice: silent (no push, no chat message)', $log === [], json_encode($log));
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock;
foreach ([200, 201] as $id) { $pending->add(1, ['id' => $id, 'name' => $id === 200 ? 'Stue' : 'Badeværelse', 'target' => 16.0], null, false, $clock, true); }
watcher($pending, [200 => $t0 + 15, 201 => -1], $log, $clock)->run();
check('one valve never confirmed by voice: the user is still told', count($log) === 1 && str_contains($log[0][0], 'Badeværelse'), json_encode($log));
$db->exec('DELETE FROM home_state'); $log = []; $clock = 1000; $t0 = $clock;
foreach ([200, 201] as $id) { $pending->add(1, ['id' => $id, 'name' => $id === 200 ? 'Stue' : 'Badeværelse', 'target' => 16.0], 9, false, $clock); }
watcher($pending, [200 => $t0 + 15, 201 => $t0 + 30], $log, $clock)->run();
check('the same by chat/text still announces the success', count($log) === 1, json_encode($log));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
