<?php

declare(strict_types=1);

/**
 * Reminder delivery runner — run every 5 minutes by cron.
 *
 * Delivers any due one-off reminders (set via set_reminder) as a Web Push, then marks
 * them sent. Each reminder is claimed atomically, so overlapping runs never double-send.
 * Exits quietly if push isn't configured.
 */

// Crontab (kept out of the block comment above so the "* / 5" doesn't close it):
//   */5 * * * * php /home/kachowdk/assistant-app/bin/reminders-cron.php >/dev/null 2>&1

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Data\Reminders;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;
use App\Notify\WebPush;

if (!WebPush::isConfigured()) {
    fwrite(STDERR, "reminders-cron: VAPID not configured; nothing to do.\n");
    exit(0);
}

$reminders = new Reminders();
$notifier  = Notifier::fromEnv();

$due  = $reminders->due();
$sent = 0;

foreach ($due as $r) {
    // Claim first — only the run that flips pending→sent delivers it.
    if (!$reminders->claim($r['id'])) {
        continue;
    }
    try {
        $notifier->notify(
            $r['user_id'],
            NotificationTypes::REMINDER,
            'Reminder',
            $r['text'],
            '/?card=reminder&rid=' . $r['id'],
        );
        $sent++;
    } catch (\Throwable $e) {
        error_log('reminders-cron: ' . $e->getMessage());
    }
}

if ($due !== []) {
    fwrite(STDERR, 'reminders-cron: delivered ' . $sent . ' of ' . count($due) . " due.\n");
}
