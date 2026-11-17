<?php

declare(strict_types=1);

/**
 * update_work_session + the change log + restore_change + the stray clock-out refusal (report #83). In-memory SQLite.
 *   php bin/change-log-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\ChangeLog;
use App\Data\WorkEvents;
use App\Tools\DeleteWorkEvent;
use App\Tools\GetChangeLog;
use App\Tools\LogWorkEvent;
use App\Tools\RestoreChange;
use App\Tools\UpdateWorkSession;

if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "pdo_sqlite not loaded\n"); exit(2); }
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
$db->exec('CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT,
    source TEXT DEFAULT "manual", note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, entity TEXT, entity_id INT, action TEXT, summary TEXT, before_json TEXT, after_json TEXT,
    tool TEXT, conversation_id INT, restored_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
// A fixed past day (winter time: Copenhagen = UTC+1): DSB 07:56–14:18 local.
$ins = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location, source) VALUES (1, ?, ?, ?, ?)');
$ins->execute(['in',  '2026-11-17 06:56:00', 'DSB', 'ios_geofence']);
$ins->execute(['out', '2026-11-17 13:18:00', 'DSB', 'ios_geofence']);
$events = new WorkEvents($db);
$log    = new ChangeLog($db);
$upd    = new UpdateWorkSession($events, $log);
$del    = new DeleteWorkEvent($events, $log);
$res    = new RestoreChange($log, $events, new \App\Data\CashEntries($db), (new ReflectionClass(\App\Data\Cash::class))->newInstanceWithoutConstructor()   /* the cash view is not used by the work cases */);
$get    = new GetChangeLog($log);
$logTool = new LogWorkEvent($events);
$S = static fn (): ?array => $events->sessionOf(1, 1);

echo "1) a clock-out with no open session is refused and points at the existing session (report #83)\n";
$r = $logTool->execute(['kind' => 'out', 'place' => 'DSB', 'at' => '2026-11-17 14:48'], 1);
check('refused, not "recorded"', $r['recorded'] === false && ($r['no_open_session'] ?? false) === true, json_encode($r));
check('it names the session and the tool to use', ($r['session']['in_id'] ?? 0) === 1 && str_contains($r['message'], 'update_work_session'), $r['message'] ?? '');
check('no stray punch was written', (int) $db->query('SELECT COUNT(*) FROM work_events')->fetchColumn() === 2);

echo "2) update_work_session moves the end in one step\n";
$r = $upd->execute(['event_id' => 1, 'end' => '14:48'], 1);
check('updated', $r['updated'] === true && $r['now']['end'] === '14:48' && $r['now']['start'] === '07:56' && $r['now']['minutes'] === 412, json_encode($r));
check('the old end is in the log', $r['change_id'] !== null && count($log->recent(1)) === 1);
check('same event ids (no delete + re-add)', (int) $db->query("SELECT COUNT(*) FROM work_events WHERE id IN (1,2)")->fetchColumn() === 2);
$bad = $upd->execute(['event_id' => 1, 'end' => '06:00'], 1);
check('an end before the start is refused', $bad['updated'] === false);
check('another user cannot touch it', $upd->execute(['event_id' => 1, 'end' => '15:00'], 2)['updated'] === false);
$cid = $r['change_id'];

echo "3) restore_change sets it back\n";
$x = $res->execute(['id' => $cid], 1);
check('restored to 14:18', $x['restored'] === true && $S()['out'] === '14:18', json_encode($x));
check('it can only be restored once', $res->execute(['id' => $cid], 1)['restored'] === false);
check('another user cannot restore it', $res->execute(['id' => $cid], 2)['restored'] === false);

echo "4) a deleted session comes back\n";
$d = $del->execute(['event_id' => 1], 1);
check('deleted and logged', $d['deleted'] === true && $d['change_id'] !== null && (int) $db->query('SELECT COUNT(*) FROM work_events')->fetchColumn() === 0);
$x = $res->execute(['id' => $d['change_id']], 1);
$back = $events->sessionOf(1, (int) $db->query('SELECT MIN(id) FROM work_events')->fetchColumn());
check('restored: the session is back with its times and place', $x['restored'] === true && $back !== null && $back['in'] === '07:56' && $back['out'] === '14:18' && $back['place'] === 'DSB', json_encode($back));
check('the punches keep their source', $db->query("SELECT COUNT(*) FROM work_events WHERE source = 'ios_geofence'")->fetchColumn() == 2);

echo "5) closing an ongoing session through update, then restoring removes the added clock-out\n";
$ins->execute(['in', '2026-11-18 07:00:00', 'DTU', 'manual']);
$openId = (int) $db->query("SELECT id FROM work_events WHERE location = 'DTU'")->fetchColumn();
$r = $upd->execute(['event_id' => $openId, 'end' => '2026-11-18 15:00'], 1);
check('the end was added', $r['updated'] === true && $r['now']['end'] === '15:00', json_encode($r));
$res->execute(['id' => $r['change_id']], 1);
check('restore leaves it ongoing again', (int) $db->query("SELECT COUNT(*) FROM work_events WHERE location = 'DTU'")->fetchColumn() === 1);

echo "6) get_change_log\n";
$g = $get->execute([], 1);
check('lists changes newest first with a restored flag', $g['available'] === true && count($g['changes']) >= 3 && $g['changes'][0]['restored'] === true && isset($g['changes'][0]['summary']), json_encode($g['changes'][0] ?? null));
check('another user sees none', $get->execute([], 2)['changes'] === []);
$bare = new PDO('sqlite::memory:');
$none = new ChangeLog($bare);
check('no table: unavailable, recording is a silent no-op', !$none->available() && $none->record(1, 'x', 1, 'delete', 's', []) === null && (new GetChangeLog($none))->execute([], 1)['available'] === false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
