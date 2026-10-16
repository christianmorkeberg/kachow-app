<?php

declare(strict_types=1);

/**
 * Daily refresh of the saved Hue name -> id list (lights, rooms, zones, scenes), so the assistant
 * can act on "turn off Le Klint" without listing everything first.
 *
 *     15 4 * * * php /home/kachowdk/assistant-app/bin/home-sync-cron.php >/dev/null 2>&1
 *
 * For every user with a home agent that is online right now, asks the bridge (through the Pico)
 * for each list and saves it. A user whose Pico is offline is skipped — the list simply stays as
 * it was and refreshes tomorrow, or whenever the assistant next lists lights. Safe to re-run.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Data\HomeDevices;
use App\Home\HueSync;
use App\Tools\GetHomeAgentSetup;

$tokens = new ApiTokens();
$sync   = new HueSync(new HomeDevices(), HueSync::viaAgent(new AgentCommands()));
$done   = 0;
$skip   = 0;

foreach ($tokens->userIdsWithScope(AgentCommands::SCOPE) as $uid) {
    $ago = $tokens->secondsSinceUse($uid, AgentCommands::SCOPE);
    if ($ago === null || $ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
        $skip++;
        continue;
    }
    try {
        $r = $sync->refresh($uid);
        $done++;
        if ($r['failed'] !== []) {
            error_log('home-sync-cron user ' . $uid . ': could not read ' . implode(', ', $r['failed']));
        }
    } catch (\Throwable $e) {
        $skip++;
        error_log('home-sync-cron user ' . $uid . ': ' . $e->getMessage());
    }
}

fwrite(STDERR, "home-sync-cron: {$done} refreshed, {$skip} skipped.\n");
exit(0);
