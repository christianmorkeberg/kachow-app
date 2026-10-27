<?php

declare(strict_types=1);

/** Thermostat probe: the log table and what ThermostatProbe::summary makes of it. In-memory SQLite, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\ThermostatSamples;
use App\Support\ThermostatProbe;
use App\Tools\GetThermostatProbe;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE thermostat_samples (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, thermostat_id INT, name TEXT, sampled_at TEXT, reported_at TEXT, current_c REAL, target_c REAL, valve_pct INT, battery_pct INT, rssi INT, read_ms INT, error TEXT)');
$S = new ThermostatSamples($db);

// A valve that reports every 5 minutes, sampled every minute; a window opens at minute 20 and the reading falls 21.0 → 19.4.
$t0 = 1_800_000_000;
$temps = [0 => 21.0, 5 => 21.0, 10 => 21.1, 15 => 21.0, 20 => 20.2, 25 => 19.4, 30 => 19.5];
$cur = 21.0; $rep = $t0;
for ($m = 0; $m <= 30; $m++) {
    if (isset($temps[$m])) { $cur = $temps[$m]; $rep = $t0 + $m * 60; }
    $S->record(1, ['thermostat_id' => 200, 'name' => 'Stue', 'reported_ts' => $rep, 'current_c' => $cur, 'target_c' => 21, 'valve_pct' => 30, 'battery_pct' => 90, 'rssi' => -60, 'read_ms' => 800], $t0 + $m * 60);
}
$S->record(1, ['thermostat_id' => 200, 'name' => 'Stue', 'read_ms' => 5000, 'error' => 'timeout'], $t0 + 31 * 60);
$S->record(2, ['thermostat_id' => 200, 'name' => 'Someone else', 'current_c' => 5], $t0);

$rows = $S->since(1, 24, null, $t0 + 32 * 60);
check('only this user\'s rows come back, oldest first', count($rows) === 32 && $rows[0]['sampled_at'] === gmdate('Y-m-d H:i:s', $t0) && (int) $rows[0]['thermostat_id'] === 200);
check('the time window is respected', count($S->since(1, 1, null, $t0 + 200 * 60)) === 0 && count($S->since(1, 1, 201, $t0 + 32 * 60)) === 0);

$sum = ThermostatProbe::summary($rows);
$v = $sum[0] ?? [];
check('one entry per valve, with the sample and error counts', count($sum) === 1 && $v['samples'] === 32 && $v['read_errors'] === 1);
check('7 distinct reports, 5 minutes apart', $v['reports'] === 7 && $v['minutes_between_reports'] === ['min' => 5.0, 'median' => 5.0, 'max' => 5.0], json_encode($v['minutes_between_reports']));
check('a reading is up to 4 minutes old when we read it', $v['reading_age_minutes']['max'] === 4.0 && $v['reading_age_minutes']['median'] === 2.0, json_encode($v['reading_age_minutes']));
check('temperature changed 5 times (21.0→21.1→21.0→20.2→19.4→19.5), biggest step 0.8', $v['temperature_changes'] === 5 && $v['biggest_step_c'] === 0.8, json_encode([$v['temperature_changes'], $v['biggest_step_c']]));
check('the fastest fall within 15 minutes: 21.1 → 19.4 (1.7) over 15 minutes', $v['fastest_fall'] === ['c' => 1.7, 'within_minutes' => 15.0], json_encode($v['fastest_fall']));

// a valve that never reports again: one report, readings get older
$S2 = [['thermostat_id' => 201, 'name' => 'Bad', 'sampled_at' => '2026-10-27 10:00:00', 'reported_at' => '2026-10-27 09:50:00', 'current_c' => 20.0, 'error' => null],
       ['thermostat_id' => 201, 'name' => 'Bad', 'sampled_at' => '2026-10-27 10:30:00', 'reported_at' => '2026-10-27 09:50:00', 'current_c' => 20.0, 'error' => null]];
$b = ThermostatProbe::summary($S2)[0];
check('a valve that stays silent: one report, no gaps, readings 10 → 40 minutes old', $b['reports'] === 1 && $b['minutes_between_reports'] === null && $b['reading_age_minutes']['max'] === 40.0 && $b['fastest_fall'] === null);
check('no rows, no summary', ThermostatProbe::summary([]) === []);

$tool = new GetThermostatProbe($S);
$r = $tool->execute(['hours' => 24, 'rows' => 3], 1);
check('the tool answers with the summary and the newest raw rows', ($r['samples'] ?? 0) > 0 && isset($r['valves']) && isset($r['newest']) && count($r['newest']) === 3);
check('no samples → a note that says how to switch it on', isset((new GetThermostatProbe(new ThermostatSamples($db)))->execute([], 9)['note']));

$old = new ThermostatSamples($db);
$old->record(1, ['thermostat_id' => 200, 'name' => 'Stue'], time() - 20 * 86400);
check('purge drops rows older than 14 days and keeps the rest', $old->purge() === 1 && (int) $db->query('SELECT COUNT(*) FROM thermostat_samples WHERE sampled_at < ' . $db->quote(gmdate('Y-m-d H:i:s', time() - 14 * 86400)))->fetchColumn() === 0);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
