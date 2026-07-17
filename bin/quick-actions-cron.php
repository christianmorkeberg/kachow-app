<?php

declare(strict_types=1);

/**
 * Daily regeneration of each user's home-screen conversation-starter chips.
 *
 *     30 4 * * * php /home/kachowdk/assistant-app/bin/quick-actions-cron.php >/dev/null 2>&1
 *
 * Runs once a day (early morning). For every user with enough history it asks a
 * cheap model for ~6 fresh, personalised starters in the user's own language and
 * caches them (quick_action_cache). The empty-screen endpoint prefers this cache
 * and falls back to frequent/default chips when a user has no (fresh) row.
 *
 * Safe to re-run; it just overwrites. Exits quietly if Gemini isn't configured.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Assistant\GeminiClient;
use App\Assistant\QuickActionGenerator;
use App\Data\QuickActions;
use App\Data\Users;

try {
    $gemini = GeminiClient::fromEnv();
} catch (\Throwable $e) {
    fwrite(STDERR, "quick-actions-cron: Gemini not configured; nothing to do.\n");
    exit(0);
}

$generator = new QuickActionGenerator($gemini, new QuickActions());
$ok = 0;
$skip = 0;

foreach ((new Users())->allIds() as $uid) {
    try {
        $result = $generator->generateFor($uid);
        if ($result === []) {
            $skip++;
        } else {
            $ok++;
        }
    } catch (\Throwable $e) {
        $skip++;
        error_log('quick-actions-cron user ' . $uid . ': ' . $e->getMessage());
    }
}

fwrite(STDERR, "quick-actions-cron: {$ok} generated, {$skip} skipped.\n");
exit(0);
