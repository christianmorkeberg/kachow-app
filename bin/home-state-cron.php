<?php

declare(strict_types=1);

/**
 * Every 5 minutes: read the lights and every radiator valve of each user whose home agent is online and keep the result in the home-state cache
 * (App\Data\HomeState). The valves report every 5 minutes (measured with the thermostat probe), so the cache is as fresh as a live read would be, and the
 * assistant, the Home card and the kiosk answer from it instead of queueing behind the Pico — which handles one command at a time.
 *
 *     cPanel: an entry every 5 minutes (minute field: star, slash, 5) running  php /home/kachowdk/assistant-app/bin/home-state-cron.php
 *
 * (or let the per-minute dispatcher run it — "let the dispatcher run the home state"). Runs at most once at a time.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

// Obey the system task's state (paused / owned by the dispatcher) and record what this run came to.
\App\Support\SystemTaskGuard::begin('home_state');

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Home\HomeEndpoint;
use App\Tools\GetHomeAgentSetup;

set_time_limit(240);
$lock = fopen(sys_get_temp_dir() . '/kachow-home-state.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "home-state: the previous run is still going.\n");
    exit(0);
}

$tokens = new ApiTokens();
$done   = 0;
$skip   = 0;

foreach ($tokens->userIdsWithScope(AgentCommands::SCOPE) as $uid) {
    $ago = $tokens->secondsSinceUse($uid, AgentCommands::SCOPE);
    if ($ago === null || $ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
        $skip++;
        continue;   // the Pico is offline: nothing to read, and nothing to queue up behind it
    }
    try {
        $r = HomeEndpoint::refresh($uid);
        $done++;
        if ($r['errors'] !== []) {
            error_log('home-state-cron user ' . $uid . ': ' . implode('; ', $r['errors']));
        }
    } catch (\Throwable $e) {
        $skip++;
        error_log('home-state-cron user ' . $uid . ': ' . $e->getMessage());
    }
}

fwrite(STDERR, "home-state-cron: {$done} refreshed, {$skip} skipped.\n");
exit(0);
