<?php

declare(strict_types=1);

/**
 * export_data can export the thermostat probe's raw samples (report #67): the source is allowed, and an export gets every sample as a row
 * (the model itself is shown at most 60). No database:
 *   php -d extension=pdo_sqlite bin/export-probe-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ThermostatSamples;
use App\Tools\ExportData;
use App\Tools\GetThermostatProbe;
use App\Tools\Tool;
use App\Tools\ToolRegistry;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

check('get_thermostat_probe is an export source', in_array('get_thermostat_probe', ExportData::SOURCES, true));

// The real tool over a real (in-memory) samples table: 300 rows.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE thermostat_samples (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, thermostat_id INT, name TEXT, sampled_at TEXT, reported_at TEXT,
    current_c REAL, target_c REAL, valve_pct INT, battery_pct INT, rssi INT, read_ms INT, error TEXT)');
$store = new ThermostatSamples($db);
$now = time();
for ($i = 0; $i < 300; $i++) {
    $store->record(1, ['thermostat_id' => 200 + ($i % 3), 'name' => 'V' . ($i % 3), 'reported_ts' => $now - 60, 'current_c' => 21.0, 'target_c' => 15.0,
        'valve_pct' => 0, 'battery_pct' => 100, 'rssi' => -70, 'read_ms' => 2000], $now - 3600 + $i * 10);
}
$tool = new GetThermostatProbe($store);

$asModel = $tool->execute(['hours' => 24, 'rows' => 500], 1);
check('the model is shown at most 60 raw rows', count($asModel['newest'] ?? []) === 60, (string) count($asModel['newest'] ?? []));
$asExport = $tool->execute(['hours' => 24, '_export' => true], 1);
check('an export gets every sample as a row', count($asExport['newest'] ?? []) === 300, (string) count($asExport['newest'] ?? []));

$registry = new ToolRegistry();
$registry->register($tool);
$built = ExportData::build($registry, ['source' => 'get_thermostat_probe', 'args' => ['hours' => 24]], 1);
check('export_data builds a table with one row per sample and the probe columns', !isset($built['error']) && count($built['rows'] ?? []) === 300
    && in_array('sampled_at', $built['columns'] ?? [], true) && in_array('valve_pct', $built['columns'] ?? [], true), json_encode(array_keys($built)));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
