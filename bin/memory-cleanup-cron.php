<?php

declare(strict_types=1);

/**
 * Scheduled memory cleanup — condenses each user's long-term MEMORY (merges
 * duplicates, drops redundant facts) with a cheap model. Conservative: dedup only,
 * delete-capped per run, ids-only audit logging (see Assistant\MemoryCondenser).
 *
 *     10 4 * * 0  php /home/kachowdk/assistant-app/bin/memory-cleanup-cron.php >/dev/null 2>&1   # weekly
 *
 * OFF by default — writing to memory is only enabled once the `memory_condense`
 * app-flag is on, so nothing touches user data until it's explicitly trusted:
 *
 *     php bin/memory-cleanup-cron.php --dry-run   # preview merges, change nothing (any time)
 *     php bin/memory-cleanup-cron.php --enable     # turn the scheduled job on
 *     php bin/memory-cleanup-cron.php --disable    # turn it back off
 *
 * Exits quietly if Gemini isn't configured.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Assistant\GeminiClient;
use App\Assistant\MemoryCondenser;
use App\Data\AppFlags;
use App\Data\Memories;
use App\Data\Users;

$flags = new AppFlags();

if (in_array('--enable', $argv, true)) {
    $flags->set('memory_condense', true);
    fwrite(STDERR, "memory-cleanup: enabled.\n");
    exit(0);
}
if (in_array('--disable', $argv, true)) {
    $flags->set('memory_condense', false);
    fwrite(STDERR, "memory-cleanup: disabled.\n");
    exit(0);
}

$dry = in_array('--dry-run', $argv, true);

if (!$dry && !$flags->isOn('memory_condense', false)) {
    fwrite(STDERR, "memory-cleanup: disabled (run with --enable to turn on, or --dry-run to preview).\n");
    exit(0);
}

try {
    $gemini = GeminiClient::fromEnv();
} catch (\Throwable $e) {
    fwrite(STDERR, "memory-cleanup: Gemini not configured; nothing to do.\n");
    exit(0);
}

$condenser = new MemoryCondenser($gemini, new Memories());

foreach ((new Users())->allIds() as $uid) {
    try {
        $r = $condenser->condenseFor($uid, !$dry);
        fwrite(STDERR, sprintf(
            "memory-cleanup user %d: %d removed, %d merged (of %d)%s\n",
            $uid,
            $r['deleted'],
            $r['updated'],
            $r['count'],
            $dry ? ' [dry-run]' : ''
        ));
    } catch (\Throwable $e) {
        error_log('memory-cleanup user ' . $uid . ': ' . $e->getMessage());
    }
}

exit(0);
