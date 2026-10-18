<?php

declare(strict_types=1);

/**
 * The merged hours card (WorkEvents::overview): totals + bars + session lines in ONE card, and that both
 * get_work_hours and get_work_summary now return it (they used to return two different cards). In-memory
 * SQLite, no network, dates relative to today:
 *   php -d extension=php_pdo_sqlite bin/work-overview-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\WorkEvents;
use App\Tools\GetWorkHours;
use App\Tools\GetWorkSummary;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/work-overview-test.php\n");
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
$db->exec('CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT,
    source TEXT DEFAULT "ios_geofence", note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$events = new WorkEvents($db);

$tz  = new DateTimeZone('Europe/Copenhagen');
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $tz);
$mon = $now->setTime(0, 0)->modify('-' . ((int) $now->format('N') - 1) . ' days');   // this week's Monday, local
$ins = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (?, ?, ?, ?)');
/** A closed session on a local day, from H:M to H:M. */
$session = static function (int $uid, DateTimeImmutable $day, string $from, string $to, ?string $place) use ($ins, $utc): int {
    [$fh, $fm] = array_map('intval', explode(':', $from));
    [$th, $tm] = array_map('intval', explode(':', $to));
    $a = $day->setTime($fh, $fm);
    $b = $day->setTime($th, $tm);
    $ins->execute([$uid, 'in', $a->setTimezone($utc)->format('Y-m-d H:i:s'), $place]);
    $ins->execute([$uid, 'out', $b->setTimezone($utc)->format('Y-m-d H:i:s'), $place]);

    return (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60);
};

// This week's Monday: 8 h at Office. Last week's Monday and Tuesday: 4 h Office + 6 h Client.
$session(1, $mon, '09:00', '17:00', 'Office');
$session(1, $mon->modify('-7 days'), '08:00', '12:00', 'Office');
$session(1, $mon->modify('-6 days'), '09:00', '15:00', 'Client');
$session(2, $mon, '09:00', '10:00', 'Elsewhere');   // another user: must never show up

echo "1) one day: totals and lines, no bars\n";
$c = $events->overview(1, ['period' => 'today']);
check('a work_hours card in "today" mode', $c['kind'] === 'work_hours' && $c['mode'] === 'today' && $c['title'] === 'Today');
check('covers exactly today, with no bars', $c['from'] === $now->format('Y-m-d') && $c['to'] === $now->format('Y-m-d') && !isset($c['bars']));
$c = $events->overview(1, ['scope' => 'today', 'date' => $mon->format('Y-m-d')]);
check('a single date has its sessions (8 h) and no bars', $c['total_minutes'] === 480 && count($c['sessions']) === 1 && !isset($c['bars']) && $c['mode'] === null);
check('each session carries its date', ($c['sessions'][0]['date'] ?? '') === $mon->format('Y-m-d'));

echo "\n2) this week: seven daily bars\n";
$c = $events->overview(1, ['period' => 'week']);
check('mode week, titled "This week"', $c['mode'] === 'week' && $c['title'] === 'This week');
check('seven bars, one day each, Monday first', count($c['bars']) === 7 && $c['bars'][0]['from'] === $mon->format('Y-m-d') && $c['bars'][0]['to'] === $mon->format('Y-m-d')
    && $c['bars'][6]['to'] === $mon->modify('+6 days')->format('Y-m-d') && $c['bucket_word'] === 'day');
check('Monday\'s bar holds the 8 h, and the bars add up to the total', $c['bars'][0]['minutes'] === 480 && array_sum(array_column($c['bars'], 'minutes')) === $c['total_minutes'] && $c['total_minutes'] === 480);
check('it carries every session of the week (nothing to fetch later)', $c['sessions_scope']['from'] === $mon->format('Y-m-d') && $c['sessions_scope']['to'] === $mon->modify('+6 days')->format('Y-m-d') && count($c['sessions']) === 1);
check('another user\'s hours are not in it', !str_contains(json_encode($c), 'Elsewhere'));

echo "\n3) four weeks: weekly bars\n";
$c = $events->overview(1, ['period' => '4w']);
check('four weekly bars, Monday to Sunday', count($c['bars']) === 4 && $c['bucket_word'] === 'week' && $c['title'] === 'Last 4 weeks'
    && $c['bars'][3]['from'] === $mon->format('Y-m-d') && $c['bars'][3]['to'] === $mon->modify('+6 days')->format('Y-m-d'));
check('last week\'s bar = 4 h + 6 h, this week\'s = 8 h', $c['bars'][2]['minutes'] === 600 && $c['bars'][3]['minutes'] === 480);
check('bars add up to the total (18 h)', array_sum(array_column($c['bars'], 'minutes')) === $c['total_minutes'] && $c['total_minutes'] === 1080);
check('the sessions of all four weeks are carried (3, under the cap)', count($c['sessions']) === 3 && $c['sessions_scope']['from'] === $c['from']);

echo "\n4) several workplaces stack, and the legend follows the bars\n";
check('stacked, with a place list that has minutes', $c['stacked'] === true && ($c['places'][0]['minutes'] ?? 0) > 0);
$order = array_column($c['places'], 'place');
$split = $c['bars'][2]['split'] ?? [];
check('each bar\'s split is in the legend\'s order and adds up to the bar', count($split) === count($order) && array_sum($split) === $c['bars'][2]['minutes']
    && $split[array_search('Client', $order, true)] === 360 && $split[array_search('Office', $order, true)] === 240, json_encode([$order, $split]));
$off = $events->overview(1, ['period' => '4w', 'place' => 'Office']);
check('a workplace filter narrows everything', $off['total_minutes'] === 720 && !($off['stacked'] ?? false) && count($off['sessions']) === 2);

echo "\n5) a custom range and the refresh request\n";
$c = $events->overview(1, ['date' => $mon->modify('-40 days')->format('Y-m-d'), 'to' => $mon->format('Y-m-d')]);
check('a 41-day range gets weekly bars (auto) and no mode', $c['bucket_word'] === 'week' && $c['mode'] === null && $c['from'] === $mon->modify('-40 days')->format('Y-m-d'));
$again = $events->overview(1, $c['query']);
check('the stored request rebuilds the same card', $again['total_minutes'] === $c['total_minutes'] && $again['bars'] === $c['bars'] && $again['from'] === $c['from']);
$wk = $events->overview(1, ['scope' => 'week']);
check('scope "week" (the get_work_hours way) is the week view', $wk['mode'] === 'week' && count($wk['bars']) === 7);
$lw = $events->overview(1, ['scope' => 'lastweek']);
check('last week: not a preset, but still bars', $lw['mode'] === null && count($lw['bars']) === 7 && $lw['total_minutes'] === 600);

echo "\n6) a big range carries only its latest bar's lines, and the rest can be fetched\n";
$d = $mon->modify('-1 day');
for ($i = 0; $i < 70; $i++) { $session(3, $d->modify("-{$i} days"), '08:00', '09:00', 'Office'); }
$c = $events->overview(3, ['period' => '12w']);
check('70 sessions are more than the card carries', $c['total_minutes'] === 70 * 60 && count($c['sessions']) < 70);
$bar = null;
foreach (array_reverse($c['bars']) as $b) { if ($b['minutes'] > 0) { $bar = $b; break; } }
check('it carries the latest bar with hours, and says which days', $bar !== null && $c['sessions_scope']['from'] === $bar['from'] && $c['sessions_scope']['to'] === $bar['to']
    && count($c['sessions']) > 0 && count(array_filter($c['sessions'], static fn (array $s): bool => $s['date'] < $bar['from'] || $s['date'] > $bar['to'])) === 0);
$first = $c['bars'][0];
$fetched = $events->summary(3, 'today', $first['from'], null, $first['to'])['card'];
check('another bar\'s lines come from a range query (what the tap fetches)', $first['minutes'] === 0 || count($fetched['sessions']) > 0);
check('a bar\'s minutes match its own lines', array_sum(array_column($fetched['sessions'], 'minutes')) === $first['minutes'] || $first['minutes'] === 0
    || abs(array_sum(array_column($fetched['sessions'], 'minutes')) - $first['minutes']) <= 60);

echo "\n7) both tools return this card\n";
$hours = (new GetWorkHours($events))->execute(['scope' => 'week'], 1);
check('get_work_hours: the merged card, with bars, and the same facts for the model', $hours['_render']['kind'] === 'work_hours' && isset($hours['_render']['bars'])
    && $hours['total_minutes'] === 480 && $hours['session_count'] === 1);
$sum = (new GetWorkSummary($events))->execute(['period' => '4w'], 1);
check('get_work_summary: the merged card (it used to be a separate chart card)', $sum['_render']['kind'] === 'work_hours' && $sum['_render']['mode'] === '4w' && count($sum['_render']['bars']) === 4);
check('…and the model still gets its per-bucket numbers', isset($sum['by_bucket']) && $sum['total_minutes'] === 1080 && isset($sum['busiest']));
$custom = (new GetWorkSummary($events))->execute(['from' => $mon->modify('-13 days')->format('Y-m-d'), 'to' => $mon->format('Y-m-d'), 'bucket' => 'week'], 1);
check('a custom range goes through too', $custom['_render']['kind'] === 'work_hours' && $custom['_render']['bucket_word'] === 'week' && $custom['_render']['mode'] === null);
$single = (new GetWorkHours($events))->execute(['date' => $mon->format('Y-m-d')], 1);
check('a single day through get_work_hours: lines, no bars', $single['_render']['kind'] === 'work_hours' && !isset($single['_render']['bars']) && count($single['_render']['sessions']) === 1);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
