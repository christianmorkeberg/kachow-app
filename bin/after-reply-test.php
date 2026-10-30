<?php

declare(strict_types=1);

/** Follow-ups after the answer: AfterReply (the generic queue), ThermostatConfirm (wait for the valves, then tell once) and set_thermostat registering it. No network, no sleeping. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;
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

echo "2) ThermostatConfirm — a fake clock, the valves report at known times\n";
function watcher(array $reports, array &$log, int &$clock, ?int &$checks = null): ThermostatConfirm
{
    // $reports: valve id => the second (from start) at which it starts reporting the new target
    return new ThermostatConfirm(
        static function (int $id) use ($reports, &$clock): ?float {
            $at = $reports[$id] ?? null;
            if ($at === null) { return 17.0; }          // never confirms
            return $at === -1 ? null : ($clock >= $at ? 16.0 : 17.0);
        },
        static function (string $t, string $b) use (&$log): void { $log['push'][] = $b; },
        static function (string $b) use (&$log): void { $log['say'][] = $b; },
        static function (int $s) use (&$clock): void { $clock += $s; },
        static function () use (&$clock): int { return $clock; }
    );
}
$items = [['id' => 200, 'name' => 'Stue', 'target' => 16.0], ['id' => 201, 'name' => 'Badeværelse', 'target' => 16.0], ['id' => 202, 'name' => 'Soveværelse', 'target' => 16.0]];

$log = []; $clock = 1000; $t0 = $clock;
$r = watcher([200 => $t0 + 15, 201 => $t0 + 60, 202 => $t0 + 140], $log, $clock)->run($items, true);
check('all three confirm: ONE push + one chat message, in Danish, naming them', count($log['push']) === 1 && count($log['say']) === 1 && $log['push'][0] === 'Radiatorerne er nu sat til 16°: Stue, Badeværelse, Soveværelse.', json_encode($log));
check('it stopped checking as soon as the last one confirmed (checks at 20, 50, 90, 150 s)', $r['checks'] === 4 && $clock - $t0 === 150 && $r['pending'] === [], json_encode($r) . ' clock+' . ($clock - $t0));

$log = []; $clock = 1000; $t0 = $clock;
$r = watcher([200 => $t0 + 10, 201 => $t0 + 10], $log, $clock)->run($items, false, 480);
check('one valve never confirms: after the time is up one message says which (English)', $r['pending'] === ['Soveværelse'] && $log['push'][0] === 'Stue, Badeværelse now set to 16°, but Soveværelse has not confirmed yet.', json_encode($log));
check('it gave up inside the time budget (480 s)', $clock - $t0 <= 480, (string) ($clock - $t0));

$log = []; $clock = 1000;
$r = watcher([], $log, $clock)->run([$items[0]], true, 480);
check('nobody confirms: a "not confirmed yet" message, not silence', $r['confirmed'] === [] && str_contains($log['push'][0], 'har bekræftet 16° endnu') || str_contains($log['push'][0] ?? '', 'Ingen af radiatorerne'), json_encode($log));

$log = []; $clock = 1000; $t0 = $clock;
$r = watcher([200 => -1], $log, $clock)->run([$items[0]], false, 100);
check('a valve that cannot be read is simply "not confirmed" (never a crash)', $r['pending'] === ['Stue'] && count($log['push']) === 1);

$log = []; $clock = 1000;
$twice = [['id' => 200, 'name' => 'Stue', 'target' => 20.0], ['id' => 200, 'name' => 'Stue', 'target' => 16.0]];
$r = watcher([200 => 1001], $log, $clock)->run($twice, false);
check('the same valve set twice: the last ask counts (16, not 20)', $r['confirmed'] === ['Stue'] && str_contains($log['push'][0], '16°'), json_encode($log));

$log = []; $clock = 1000;
$mixed = [['id' => 200, 'name' => 'Stue', 'target' => 16.0], ['id' => 201, 'name' => 'Badeværelse', 'target' => 20.0]];
$w = new ThermostatConfirm(
    static fn (int $id): ?float => $id === 200 ? 16.0 : 20.0,
    static function (string $t, string $b) use (&$log): void { $log['push'][] = $b; },
    static function (string $b) use (&$log): void { $log['say'][] = $b; },
    static function (int $s) use (&$clock): void { $clock += $s; },
    static function () use (&$clock): int { return $clock; }
);
$w->run($mixed, true);
check('different temperatures in one request are named per valve', $log['push'][0] === 'Radiatorerne er nu sat: Stue 16°, Badeværelse 20°.', $log['push'][0] ?? '');

echo "3) set_thermostat queues ONE watcher and only promises when it can run\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'thermostats', 'Stue=200, Badeværelse=201')");
$queued = new class implements Tool {
    public function name(): string { return 'home_device_call'; }
    public function description(): string { return 'fake'; }
    public function parameters(): array { return ['type' => 'object', 'properties' => []]; }
    public function execute(array $a, int $userId): array { return ['queued' => true, 'command_id' => 7]; }
};
$tool = new SetThermostat($queued, new UserSettings($db));
AfterReply::reset();
$r = $tool->execute(['thermostat' => 'Stue', 'temperature_c' => 16], 1);
check('without AfterReply: sent, no promise, the model is told to offer a check', $r['set'] === 'sent' && $r['will_notify'] === false && str_contains($r['note'], 'offer to check'));
check('...and nothing queued', AfterReply::hasWork() === false);
AfterReply::enable(['danish' => true]);
$a = $tool->execute(['thermostat' => 'Stue', 'temperature_c' => 16], 1);
$b = $tool->execute(['thermostat' => 'Badeværelse', 'temperature_c' => 16], 1);
check('with AfterReply: sent + will_notify, and the model is told NOT to offer a check', $a['set'] === 'sent' && $a['will_notify'] === true && str_contains($a['note'], 'do not offer to check'));
$jobs = new ReflectionProperty(AfterReply::class, 'jobs'); $jobs->setAccessible(true);
$q = $jobs->getValue();
check('two valves of one turn = ONE job with two items (one watcher, one push)', count($q) === 1 && count($q['thermostat_confirm']['items']) === 2 && $q['thermostat_confirm']['items'][1]['name'] === 'Badeværelse', json_encode(array_keys($q)));
AfterReply::reset();

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
