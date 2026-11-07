<?php

declare(strict_types=1);

/**
 * Every minute: the safety net for radiator targets that were SENT but not yet confirmed by the valve (App\Home\PendingSets). The normal follow-ups — the
 * gateway's script and the watcher that runs after the chat reply — can both fail silently (a host that does not let a request run on, a script that is not
 * installed). This job looks at every waiting batch itself: it confirms the valves that report the asked target, sends the ones that do not their target AGAIN
 * (90 s and 240 s after the last send), and at 8 minutes tells the user what did not arrive (push + a message in the chat). A batch a live watcher touched in the
 * last 2 minutes is left to it.
 *
 *     cPanel / container cron: every minute  php bin/thermostat-watch-cron.php   (or let the per-minute dispatcher run it)
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

\App\Support\SystemTaskGuard::begin('thermostat_watch');

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\Connections;
use App\Data\HomeDevices;
use App\Data\HomeState;
use App\Data\UserSettings;
use App\Home\ConfirmDelivery;
use App\Home\HomeOwner;
use App\Home\PendingSets;
use App\Home\ThermostatConfirm;
use App\Home\ThermostatService;
use App\Home\HueSync;
use App\Tools\HomeDeviceCall;

set_time_limit(100);
$lock = fopen(sys_get_temp_dir() . '/kachow-thermostat-watch.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "thermostat-watch: the previous run is still going.\n");
    exit(0);
}

$agent    = new AgentCommands();
$tokens   = new ApiTokens();
$owner    = new HomeOwner($tokens, new Connections());
$home     = new HomeDeviceCall($agent, $tokens, new HueSync(new HomeDevices(), HueSync::viaAgent($agent)), $owner);
$settings = new UserSettings();
$state    = new HomeState(null, $owner);
$pending  = new PendingSets($state);

$waiting = $pending->usersWaiting();
$counts  = ['none' => 0, 'busy' => 0, 'confirmed' => 0, 'waiting' => 0, 'deadline' => 0];
foreach ($waiting as $uid) {
    try {
        $svc = new ThermostatService($home, $settings, $state);
        $res = (new ThermostatConfirm(
            $pending,
            $uid,
            static fn (int $valve): ?float => $svc->targetOf($uid, $valve),
            new ConfirmDelivery($uid),
            static function (int $s): void { sleep($s); },
            static fn (): int => time(),
            static fn (int $valve, float $t): bool => $svc->resend($uid, $valve, $t)
        ))->tick(120);
        $counts[$res['outcome']] = ($counts[$res['outcome']] ?? 0) + 1;
    } catch (\Throwable $e) {
        error_log('thermostat-watch user ' . $uid . ': ' . $e->getMessage());
    }
}
// A heartbeat, so set_thermostat knows a follow-up is guaranteed even when the host cannot run one after the reply.
foreach ($tokens->userIdsWithScope(AgentCommands::SCOPE) as $uid) {
    $state->put((int) $uid, 'thermostat_watch_beat', ['at' => time()]);
}
fwrite(STDERR, 'thermostat-watch: ' . count($waiting) . ' batch(es): ' . json_encode($counts) . "\n");
exit(0);
