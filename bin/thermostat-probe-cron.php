<?php

declare(strict_types=1);

/**
 * Thermostat probe — an experiment. Once a minute it reads every radiator thermostat of each user whose home agent is online
 * and logs what the valve says, with the gateway's own "last heard" time, so we can see how often the valves REALLY report
 * (and whether an open window shows up fast enough to be detected). Off by default: the system task "Thermostat probe" is
 * paused until someone resumes it (update_system_task, or the routines card). It runs at most once at a time and deletes
 * its own rows after 14 days.
 *
 *     * * * * * php /home/kachowdk/assistant-app/bin/thermostat-probe-cron.php >/dev/null 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

// Obey the system task's state (paused) and record what this run came to.
\App\Support\SystemTaskGuard::begin('thermostat_probe');

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\Connections;
use App\Data\HomeDevices;
use App\Data\ThermostatSamples;
use App\Data\UserSettings;
use App\Home\HueSync;
use App\Home\ThermostatService;
use App\Tools\GetHomeAgentSetup;
use App\Tools\HomeDeviceCall;
use App\Home\HomeOwner;

set_time_limit(55);
$lock = fopen(sys_get_temp_dir() . '/kachow-thermostat-probe.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "thermostat-probe: the previous run is still going.\n");
    exit(0);
}

$tokens  = new ApiTokens();
$agent   = new AgentCommands();
$home    = new HomeDeviceCall($agent, $tokens, new HueSync(new HomeDevices(), HueSync::viaAgent($agent)), new HomeOwner($tokens, new Connections()));
$service = new ThermostatService($home, new UserSettings());
$samples = new ThermostatSamples();
$logged  = 0;
$failed  = 0;
$started = microtime(true);

foreach ($tokens->userIdsWithScope(AgentCommands::SCOPE) as $uid) {
    $ago = $tokens->secondsSinceUse($uid, AgentCommands::SCOPE);
    if ($ago === null || $ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
        continue;   // the Pico is offline: nothing to read, and nothing to queue up behind it
    }
    try {
        $list = $service->list($uid);
        foreach ($list['list'] as $t) {
            if (microtime(true) - $started > 45) {
                break 2;
            }
            $t0 = microtime(true);
            $r  = $service->read($uid, $t);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if (isset($r['error'])) {
                $samples->record($uid, ['thermostat_id' => $t['id'], 'name' => $t['name'], 'read_ms' => $ms, 'error' => $r['error']]);
                $failed++;
                continue;
            }
            $row = $r['row'];
            $samples->record($uid, [
                'thermostat_id' => $t['id'], 'name' => $t['name'], 'reported_ts' => $row['updated_ts'] ?? null,
                'current_c' => $row['current_c'] ?? null, 'target_c' => $row['target_c'] ?? null, 'valve_pct' => $row['valve_open_pct'] ?? null,
                'battery_pct' => $row['battery_pct'] ?? null, 'rssi' => $row['signal_dbm'] ?? null, 'read_ms' => $ms,
            ]);
            $logged++;
        }
    } catch (\Throwable $e) {
        $failed++;
        error_log('thermostat-probe user ' . $uid . ': ' . $e->getMessage());
    }
}
if ((int) gmdate('i') === 0) {
    $samples->purge();
}

fwrite(STDERR, "thermostat-probe: {$logged} logged, {$failed} failed.\n");
exit(0);
