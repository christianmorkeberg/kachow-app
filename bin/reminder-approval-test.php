<?php

declare(strict_types=1);

/**
 * Reminders that stay open until approved: Data\Reminders (open / approve / nudge / cancel / card), the resolve_reminder
 * and list_reminders tools, and the app-icon badge in the push payload. In-memory SQLite, no network:
 *   php -d extension=pdo_sqlite bin/reminder-approval-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\NotificationPrefs;
use App\Data\PushSubscriptions;
use App\Data\Reminders;
use App\Notify\Notifier;
use App\Notify\Sender;
use App\Tools\CancelReminder;
use App\Tools\ListReminders;
use App\Tools\ResolveReminder;
use App\Tools\SetReminder;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=pdo_sqlite bin/reminder-approval-test.php\n");
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
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
$db->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (string $s): int => (int) strtotime($s . ' UTC'), 1);
$db->exec("CREATE TABLE reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, remind_at TEXT NULL, place_id INT NULL,
    place_type TEXT NULL, place_label TEXT NULL, place_armed INT NOT NULL DEFAULT 1, text TEXT, status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT, approved_at TEXT NULL)");

$rem  = new Reminders($db);
$past = gmdate('Y-m-d H:i:s', time() - 60);
$soon = gmdate('Y-m-d H:i:s', time() + 3600);

echo "1) a reminder stays open after it fires\n";
$a = $rem->create(1, $past, 'Take the pills');
$b = $rem->create(1, $soon, 'Call the plumber');
check('before it fires nothing is open', $rem->openCount(1) === 0 && $rem->openFor(1) === []);
check('the unfired one is upcoming', array_column($rem->upcoming(1), 'id') === [$a, $b]);
check('claim (what the cron does) fires it', $rem->claim($a));
check('now it is open, and counted', $rem->openCount(1) === 1 && $rem->openFor(1)[0]['id'] === $a);
check('and no longer "upcoming"', array_column($rem->upcoming(1), 'id') === [$b]);
check('another user sees nothing', $rem->openCount(2) === 0 && !$rem->approve(2, $a));

echo "\n2) approve\n";
check('approving closes it', $rem->approve(1, $a) && $rem->openCount(1) === 0);
check('approving twice is a no-op', !$rem->approve(1, $a));
check('an unfired reminder cannot be approved', !$rem->approve(1, $b));

echo "\n3) nudge on request\n";
$c = $rem->create(1, $past, 'Move the laundry');
$rem->claim($c);
$at = Reminders::futureUtc('', 30);
check('futureUtc: now + minutes', $at !== null && strtotime($at . ' UTC') > time() + 1700);
check('futureUtc: rejects the past and junk', Reminders::futureUtc('2020-01-01 09:00') === null && Reminders::futureUtc('', 0) === null && Reminders::futureUtc('not a time') === null);
check('nudge keeps it open and schedules it', $rem->nudge(1, $c, $at) && $rem->openCount(1) === 1);
$open = $rem->openFor(1)[0];
check('the open list shows the nudge time', $open['id'] === $c && $open['nudge'] === $at);
check('a nudged one is not in "upcoming" twice', !in_array($c, array_column($rem->upcoming(1), 'id'), true));
check('it is not due yet', array_column($rem->due(), 'id') === []);
$db->exec("UPDATE reminders SET remind_at = '" . $past . "' WHERE id = " . $c);
check('when the nudge is due the cron fires it again', array_column($rem->due(), 'id') === [$c] && $rem->claim($c) && $rem->openCount(1) === 1);
check('after firing there is no pending nudge', $rem->openFor(1)[0]['nudge'] === null);
check('a nudge on a closed reminder fails', $rem->approve(1, $c) && !$rem->nudge(1, $c, $at));
check('approving a nudged reminder drops the nudge', ($d = $rem->create(1, $past, 'X')) > 0 && $rem->claim($d) && $rem->nudge(1, $d, $at) && $rem->approve(1, $d) && $rem->due() === [] && $rem->openCount(1) === 0);

echo "\n4) cancel works on open ones too\n";
$e = $rem->create(1, $past, 'Dismiss me');
$rem->claim($e);
check('cancelling an open reminder removes it', $rem->cancel(1, $e) && $rem->openCount(1) === 0);
check('cancelling an approved one does nothing', !$rem->cancel(1, $a));

echo "\n5) the card\n";
$f = $rem->create(1, $past, 'Water the plants');
$rem->claim($f);
$card = $rem->card(1, $f);
check('kind, focus and badge', $card['kind'] === 'reminders' && $card['focus'] === $f && $card['badge'] === 1);
check('open item has local labels', $card['open'][0]['id'] === $f && preg_match('/^\w{3} \d+ \w{3}, \d\d:\d\d$/', $card['open'][0]['fired']) === 1);
check('upcoming lists the unfired one', array_column($card['upcoming'], 'id') === [$b]);

echo "\n6) tools\n";
$resolve = new ResolveReminder($rem);
$list    = new ListReminders($rem);
$r = $list->execute([], 1);
check('list_reminders returns the card to render and the open ids', $r['_render']['kind'] === 'reminders' && $r['open_count'] === 1 && $r['open'][0]['id'] === $f);
check('resolve done', ($x = $resolve->execute(['id' => $f, 'action' => 'done'], 1)) && ($x['approved'] ?? false) === true && $x['still_open'] === 0);
check('resolve done again errors', isset($resolve->execute(['id' => $f, 'action' => 'done'], 1)['error']));
$g = $rem->create(1, $past, 'Stretch'); $rem->claim($g);
$n = $resolve->execute(['id' => $g, 'action' => 'nudge', 'minutes' => 45], 1);
check('resolve nudge by minutes', ($n['nudged'] ?? false) === true && $rem->openCount(1) === 1);
check('resolve nudge needs a time', isset($resolve->execute(['id' => $g, 'action' => 'nudge'], 1)['error']));
check('resolve nudge by local time', ($resolve->execute(['id' => $g, 'action' => 'nudge', 'at' => date('Y-m-d H:i', time() + 7200 + 86400)], 1)['nudged'] ?? false) === true);
check('bad action errors', isset($resolve->execute(['id' => $g, 'action' => 'nope'], 1)['error']));
check('cancel_reminder reaches open ones', (new CancelReminder($rem))->execute(['id' => $g], 1)['cancelled'] === true);
$db->exec('CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY, user_id INT, endpoint TEXT, p256dh TEXT, auth TEXT, created_at TEXT, last_sent_at TEXT)');
$db->exec('CREATE TABLE notification_prefs (user_id INT, type_key TEXT, enabled INT)');
$db->exec("INSERT INTO push_subscriptions (id, user_id, endpoint, p256dh, auth) VALUES (1, 1, 'https://push.example/1', 'k', 'a')");
$subs = new PushSubscriptions($db);
$s = (new SetReminder($rem, $subs))->execute(['text' => 'Later', 'at' => date('Y-m-d H:i', time() + 86400)], 1);
check('set_reminder still works through the shared parser', ($s['scheduled'] ?? false) === true && preg_match('/^\w{3} \d+ \w{3}, \d\d:\d\d$/', $s['when_local']) === 1);
check('set_reminder rejects the past', isset((new SetReminder($rem, $subs))->execute(['text' => 'Old', 'at' => '2020-01-01 09:00'], 1)['error']));

echo "\n6b) a mistaken tick can be restored\n";
$m = $rem->create(1, $past, 'vps'); $rem->claim($m);
$before = $rem->openCount(1);
check('approve, then it is in the recently-approved list', $rem->approve(1, $m) && ($rem->recentlyApproved(1)[0]['id'] ?? 0) === $m && $rem->openCount(1) === $before - 1);
check('the card carries it as done (newest first, local label)', ($cd = $rem->card(1)['done'])[0]['id'] === $m && preg_match('/^\w{3} \d+ \w{3}, \d\d:\d\d$/', $cd[0]['approved']) === 1);
check('list_reminders offers it for restoring', ($list->execute([], 1)['recently_approved'][0]['id'] ?? 0) === $m);
check('reopen puts it back as an open reminder', $rem->reopen(1, $m) && $rem->openCount(1) === $before && !in_array($m, array_column($rem->recentlyApproved(1), 'id'), true));
check('it is the same reminder (not a new one) and not nudged', array_values(array_filter($rem->openFor(1), static fn (array $r): bool => $r['id'] === $m))[0]['nudge'] === null);
check('reopening an open one fails', !$rem->reopen(1, $m));
check("another user cannot reopen it", $rem->approve(1, $m) && !$rem->reopen(2, $m));
check('resolve_reminder reopen works and counts', ($resolve->execute(['id' => $m, 'action' => 'reopen'], 1)['reopened'] ?? false) === true);
check('resolve_reminder reopen on an open one errors', isset($resolve->execute(['id' => $m, 'action' => 'reopen'], 1)['error']));
$cx = $rem->create(1, $past, 'to cancel'); $rem->claim($cx); $rem->cancel(1, $cx);
check('a cancelled reminder cannot be reopened', !$rem->reopen(1, $cx) && !in_array($cx, array_column($rem->recentlyApproved(1), 'id'), true));
$db->exec("UPDATE reminders SET approved_at = '" . gmdate('Y-m-d H:i:s', time() - 5 * 86400) . "' WHERE id = " . $a);
check('an old tick (5 days) is not offered any more', !in_array($a, array_column($rem->recentlyApproved(1), 'id'), true));
$rem->approve(1, $m);

echo "\n7) the push carries the badge\n";
$h = $rem->create(1, $past, 'Badge me'); $rem->claim($h);
$sender = new class implements Sender {
    public array $sent = [];
    public function send(array $sub, string $payload): string { $this->sent[] = json_decode($payload, true); return 'ok'; }
};
$n = new Notifier($subs, new NotificationPrefs($db), $sender, null, $rem);
$n->sendTest(1);
check('payload has badge = open count', ($sender->sent[0]['badge'] ?? null) === $rem->openCount(1) && $rem->openCount(1) === 1);
$rem->approve(1, $h);
$n->sendTest(1);
check('after approving the next push clears it (0)', ($sender->sent[1]['badge'] ?? null) === 0);
$n2 = new Notifier($subs, new NotificationPrefs($db), $sender);
$n2->sendTest(1);
check('without a Reminders source no badge key is sent', !array_key_exists('badge', $sender->sent[2]));

echo "\n" . ($fail === 0 ? "OK — {$pass} checks passed.\n" : "FAILED — {$fail} of " . ($pass + $fail) . ".\n");
exit($fail === 0 ? 0 : 1);
