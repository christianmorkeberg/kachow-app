<?php

declare(strict_types=1);

/**
 * Every 5 minutes: read every smart plug that measures power (Tuya cloud) and keep the reading (App\Data\PowerReadings), so "how much did the
 * freezer use this week" has data. A device that reports no power (a plain on/off plug, a lamp) is skipped. Needs TUYA_ACCESS_ID,
 * TUYA_ACCESS_SECRET, TUYA_REGION (and optionally TUYA_USER_ID, default 1) in the server's .env.
 *
 *     cPanel: an entry every 5 minutes running  php /home/kachowdk/assistant-app/bin/power-cron.php   (or let the dispatcher run it)
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

\App\Support\SystemTaskGuard::begin('power_readings');

use App\Data\PowerReadings;
use App\Energy\Tuya;

set_time_limit(120);
$tuya = Tuya::fromEnv();
if (!$tuya->isConfigured()) {
    echo "power: Tuya is not configured (TUYA_ACCESS_ID / TUYA_ACCESS_SECRET / TUYA_REGION) — nothing to do.\n";
    exit(0);
}

$store  = new PowerReadings();
$userId = Tuya::ownerUserId();
$saved  = 0;
$skipped = 0;
try {
    foreach ($tuya->devices() as $d) {
        $status = $d['status'] !== [] ? $d['status'] : ($d['online'] ? $tuya->status($d['id']) : []);
        if (!array_key_exists('cur_power', $status) && !array_key_exists('add_ele', $status)) {
            $skipped++;
            continue;
        }
        $r = Tuya::reading($status, $tuya->scales($d['id']));
        if ($r === null) {
            $skipped++;
            continue;
        }
        if (!$d['online']) {
            $r['watts'] = null;   // an offline plug's "last value" is not a measurement: keep the row, leave the load out
        }
        $store->add($userId, $d['id'], $d['name'], $d['online'], $r);
        $saved++;
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'power: ' . $e->getMessage() . "\n");
    exit(1);
}
if (random_int(1, 300) === 1) {
    $store->prune();
}
echo "power: saved {$saved} reading" . ($saved === 1 ? '' : 's') . ", skipped {$skipped} device" . ($skipped === 1 ? '' : 's') . " that do not measure power.\n";
