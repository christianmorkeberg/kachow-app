<?php

declare(strict_types=1);

/**
 * Notification history: Data\NotificationHistory and Notifier recording what it sends. In-memory
 * SQLite, no network — the push sender is a stand-in:
 *   php -d extension=php_pdo_sqlite bin/notification-history-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\NotificationHistory;
use App\Data\NotificationPrefs;
use App\Data\PushSubscriptions;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;
use App\Notify\Sender;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/notification-history-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE notification_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, type TEXT, title TEXT, body TEXT, url TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, read_at TEXT)');
$db->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);   // MySQL function used by PushSubscriptions::touch
$h = new NotificationHistory($db);

echo "1) storing and listing\n";
$a = $h->add(1, 'reminder', 'Reminder', 'Call mum', '/?card=reminder&rid=5');
$b = $h->add(1, 'scheduled_task', 'Weather', 'Rain at 15:00', '/?conv=288');
$c = $h->add(1, 'checkout_nudge', 'Still clocked in', 'Since 08:50', null);
$h->add(2, 'reminder', 'Someone else', 'private', null);
$list = $h->list(1);
check('newest first, only this user', array_column($list, 'id') === [$c, $b, $a], json_encode(array_column($list, 'id')));
check('title, body, link and unread state', $list[1]['title'] === 'Weather' && $list[1]['url'] === '/?conv=288' && $list[1]['read'] === false);
check('a push with no link keeps null', $list[0]['url'] === null);
check('unread count', $h->unreadCount(1) === 3 && $h->unreadCount(2) === 1);
check('paging back', array_column($h->list(1, 50, $b), 'id') === [$a]);
check('long text is capped', mb_strlen($h->list(1, 1)[0]['title']) <= 200 && $h->add(1, 'x', str_repeat('t', 500), str_repeat('b', 5000), null) > 0
    && mb_strlen($h->list(1, 1)[0]['body']) <= 2000);

echo "\n2) reading\n";
check('marking one read', $h->markRead(1, $a) === true && $h->markRead(1, $a) === false);
check("another user's entry cannot be marked", $h->markRead(2, $b) === false && $h->unreadCount(1) === 3);
check('opening what it points to reads it', $h->markReadByUrl(1, '/?conv=288') === 1);
check('a different link does not', $h->markReadByUrl(1, '/?conv=999') === 0);
check('read-all', $h->markAllRead(1) >= 1 && $h->unreadCount(1) === 0 && $h->unreadCount(2) === 1);
check('read state is reported', $h->list(1)[0]['read'] === true);

echo "\n3) retention\n";
$old = $h->add(1, 'reminder', 'Old', 'x', null);
$db->exec("UPDATE notification_history SET created_at = '2000-01-01 00:00:00' WHERE id = {$old}");
check('old entries are purged, recent ones kept', $h->purgeOld(1) === 1 && !in_array($old, array_column($h->list(1, 100), 'id'), true) && count($h->list(1)) >= 3);

echo "\n4) Notifier records what it sends\n";
$db->exec('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INT, endpoint TEXT, p256dh TEXT, auth TEXT, created_at TEXT, last_sent_at TEXT)');
$db->exec('CREATE TABLE notification_prefs (user_id INT, type_key TEXT, enabled INT)');
$sender = new class implements Sender {
    public array $sent = [];
    public function send(array $sub, string $payload): string { $this->sent[] = $payload; return 'ok'; }
};
$n = new Notifier(new PushSubscriptions($db), new NotificationPrefs($db), $sender, $h);
$before = count($h->list(1, 100));
$reached = $n->notify(1, NotificationTypes::REMINDER, 'Reminder', 'Buy milk', '/?card=reminder&rid=9');
check('with no device the push reaches nobody …', $reached === 0);
check('… yet it is in the history (a missed push can still be read)', count($h->list(1, 100)) === $before + 1 && $h->list(1)[0]['body'] === 'Buy milk');
$db->exec("INSERT INTO push_subscriptions (id, user_id, endpoint, p256dh, auth) VALUES (1, 1, 'https://push.example/1', 'k', 'a')");
check('with a device it is sent AND recorded', $n->notify(1, NotificationTypes::WORK_LOG_NUDGE, 'Log your day', 'What did you do?') === 1 && count($sender->sent) === 1);
$e = $h->list(1)[0];
check('the default tap target is recorded', $e['url'] === NotificationTypes::deepLink(NotificationTypes::WORK_LOG_NUDGE) && $e['url'] !== null, (string) $e['url']);
$db->exec("INSERT INTO notification_prefs (user_id, type_key, enabled) VALUES (1, 'weekly_summary', 0)");
$cnt = count($h->list(1, 100));
$n->notify(1, NotificationTypes::WEEKLY_SUMMARY, 'Week', 'summary');
check('a type the user turned off is neither sent nor listed', count($h->list(1, 100)) === $cnt);
$n->sendTest(1);
check('a test push is not history', count($h->list(1, 100)) === $cnt);
$broken = new Notifier(new PushSubscriptions($db), new NotificationPrefs($db), $sender, new NotificationHistory(new PDO('sqlite::memory:')));
check('a failing history never blocks the push', $broken->notify(1, NotificationTypes::REMINDER, 'R', 'b') === 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
