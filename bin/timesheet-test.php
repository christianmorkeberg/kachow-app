<?php

declare(strict_types=1);

/** The timesheet: hours per day from clock sessions, rounding, formats, CSV, printable page, the generic export_data tool. SQLite, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Support\Timesheet;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$s = static fn (string $date, string $in, ?string $out, int $min, bool $ongoing = false): array => ['date' => $date, 'in' => $in, 'out' => $out, 'ongoing' => $ongoing, 'minutes' => $min];
$sessions = [
    $s('2026-10-14', '09:30', '16:29', 419), $s('2026-10-13', '07:48', '17:22', 574), $s('2026-10-14', '08:20', '09:00', 40),
    $s('2026-10-14', '18:44', '19:21', 37), $s('2026-10-23', '07:50', null, 257, true),
];

echo "1) grouping per day\n";
$sheet = Timesheet::build($sessions);
check('one line per day, oldest first', array_column($sheet['days'], 'date') === ['2026-10-13', '2026-10-14', '2026-10-23']);
check('intervals sorted by start and the day summed', array_column($sheet['days'][1]['intervals'], 'in') === ['08:20', '09:30', '18:44'] && $sheet['days'][1]['minutes'] === 496);
check('total is the sum of the days', $sheet['total_minutes'] === 574 + 496 + 257);
check('weekday from the date (Tuesday = 2, Friday = 5)', $sheet['days'][0]['weekday'] === 2 && $sheet['days'][2]['weekday'] === 5);
check('no sessions → empty sheet, zero total', Timesheet::build([])['days'] === [] && Timesheet::build([])['total_minutes'] === 0);

echo "2) rounding\n";
$r = Timesheet::build($sessions, 15);
check('each day rounds to the nearest quarter, the total adds the rounded days', array_column($r['days'], 'counted') === [570, 495, 255] && $r['total_minutes'] === 1320);
check('the exact minutes are kept beside the rounded ones', $r['days'][0]['minutes'] === 574);
check('an unsupported rounding is ignored', Timesheet::build($sessions, 7)['round'] === 0);

echo "2b) lunch break\n";
$bk = Timesheet::build($sessions, 0, [], 30);
check('30 min off each day over 4 h (the 40-min-only day would not count, here all three days exceed)', array_column($bk['days'], 'counted') === [544, 466, 227] && array_column($bk['days'], 'break') === [30, 30, 30] && $bk['total_minutes'] === 1237, json_encode(array_column($bk['days'], 'counted')));
$short = Timesheet::build([$s('2026-10-15', '09:00', '12:00', 180), $s('2026-10-16', '09:00', '13:00', 240), $s('2026-10-17', '09:00', '13:01', 241)], 0, [], 30);
check('only days with MORE than 4 h lose the break (exactly 4 h keeps it)', array_column($short['days'], 'break') === [0, 0, 30] && array_column($short['days'], 'counted') === [180, 240, 211]);
check('the break is capped at the day and applied before rounding', Timesheet::build([$s('2026-10-15', '09:00', '09:20', 20)], 0, [], 30, 0)['days'][0]['counted'] === 0 && Timesheet::build([$s('2026-10-15', '09:00', '14:00', 300)], 15, [], 30)['days'][0]['counted'] === 270);
$bkRows = Timesheet::csvRows($bk, 'da');
check('CSV gets a Pause column and a break total', $bkRows[0] === ['Dato', 'Ugedag', 'Tidspunkter', 'Pause', 'Timer', 'Timer (decimal)'] && $bkRows[1][3] === '0:30' && $bkRows[4][3] === '1:30' && $bkRows[4][4] === '20:37', json_encode($bkRows[4]));
$bkHtml = Timesheet::html($bk, 'da', ['job' => 'Office']);
check('printed page: Pause column and the note', str_contains($bkHtml, '<th class="n">Pause</th>') && str_contains($bkHtml, 'fratrukket 30 min. frokostpause') && str_contains($bkHtml, 'mere end 4 timers'));
check('no break → the old columns', count(Timesheet::csvRows($sheet, 'da')[0]) === 5);

echo "3) formats\n";
check('h:mm', Timesheet::hm(574) === '9:34' && Timesheet::hm(61) === '1:01' && Timesheet::hm(0) === '0:00');
check('decimal with comma for Danish, point for English', Timesheet::decimal(574, 'da') === '9,57' && Timesheet::decimal(574, 'en') === '9.57' && Timesheet::decimal(1978, 'da') === '32,97');
check('times, with the running one marked', Timesheet::times($sheet['days'][2], 'da') === '07:50–igangværende' && Timesheet::times($sheet['days'][2], 'en') === '07:50–running'
    && Timesheet::times($sheet['days'][1], 'da') === '08:20–09:00, 09:30–16:29, 18:44–19:21');

echo "4) CSV rows\n";
$rows = Timesheet::csvRows($sheet, 'da');
check('header, one row per day, total row', count($rows) === 5 && $rows[0] === ['Dato', 'Ugedag', 'Tidspunkter', 'Timer', 'Timer (decimal)']);
check('a day row: date, weekday, times, h:mm, decimal comma', $rows[1] === ['2026-10-13', 'Tirsdag', '07:48–17:22', '9:34', '9,57']);
check('total row', $rows[4][0] === 'I alt' && $rows[4][3] === '22:07' && $rows[4][4] === '22,12', json_encode($rows[4]));
$en = Timesheet::csvRows($sheet, 'en', true);
check('English with a notes column', $en[0][5] === 'Description' && $en[1][1] === 'Tuesday' && $en[1][4] === '9.57' && count($en[1]) === 6);
$notes = Timesheet::build($sessions, 0, ['2026-10-13' => ' Migration work ', '2026-10-14' => '']);
check('notes are trimmed and empty ones dropped', $notes['days'][0]['note'] === 'Migration work' && $notes['days'][1]['note'] === null);

echo "5) printable page\n";
$html = Timesheet::html(Timesheet::build($sessions, 15, ['2026-10-13' => '<b>x</b>']), 'da', ['person' => 'Alex <script>', 'customer' => 'Client Z & Co', 'job' => 'Office', 'period' => '01.10.2026 – 23.10.2026', 'made' => '23.10.2026'], true);
check('a standalone page with the title, the people and the period', str_starts_with($html, '<!doctype html>') && str_contains($html, '<h1>Timeseddel</h1>') && str_contains($html, 'Client Z &amp; Co') && str_contains($html, '01.10.2026'));
check('everything user-supplied is escaped', !str_contains($html, '<script>') && !str_contains($html, '<b>x</b>') && str_contains($html, 'Alex &lt;script&gt;') && str_contains($html, '&lt;b&gt;x&lt;/b&gt;'));
check('dates, hours, total, rounding note and a print button that is hidden when printing', str_contains($html, '13.10.2026') && str_contains($html, '9:30') && str_contains($html, '22:00') && str_contains($html, 'nærmeste 15 min') && str_contains($html, '@media print{.bar{display:none}'));
$enHtml = Timesheet::html(Timesheet::build($sessions), 'en', [], false);
check('English page, no notes column, no rounding note', str_contains($enHtml, '<h1>Timesheet</h1>') && !str_contains($enHtml, 'Description') && !str_contains($enHtml, 'rounded to'));

$plain = Timesheet::html(Timesheet::build($sessions), 'da', ['title' => 'Timeseddel – Studio X', 'person' => 'Studio X', 'made' => '23.10.2026']);
check('a custom heading replaces the default (also in <title>) and the sender name is printed', str_contains($plain, '<h1>Timeseddel – Studio X</h1>') && str_contains($plain, '<title>Timeseddel – Studio X</title>') && str_contains($plain, '<span>Navn</span> Studio X'));
check('no signature block by default, one when asked', !str_contains($plain, 'class="sign"') && !str_contains($plain, 'Underskrift') && str_contains(Timesheet::html(Timesheet::build($sessions), 'da', [], false, true), 'Underskrift'));

echo "6) generic table export\n";
use App\Support\DataExport;
$res = ['range' => 'x', 'total' => 3, 'items' => [['date' => '2026-10-01', 'amount' => 12.5, 'paid' => true, 'note' => null, 'nested' => ['a' => 1], '_hidden' => 'x'], ['date' => '2026-10-02', 'amount' => 7, 'paid' => false, 'note' => 'Lunch; "quoted"']], 'tags' => [['k' => 'v']], '_render' => ['kind' => 'card']];
$t = DataExport::table($res);
check('picks the longest list of records, scalar columns only, hidden and nested dropped', $t['key'] === 'items' && $t['columns'] === ['date', 'amount', 'paid', 'note'], json_encode($t['columns']));
check('cells are strings (null empty, bool 1/0)', $t['rows'][0] === ['2026-10-01', '12.5', '1', ''] && $t['rows'][1][3] === 'Lunch; "quoted"');
check('columns can be chosen and ordered, unknown ones ignored', DataExport::table($res, null, ['amount', 'nope', 'date'])['columns'] === ['amount', 'date']);
check('an explicit list key wins', DataExport::table($res, 'tags')['columns'] === ['k']);
check('no list of records → null', DataExport::table(['a' => 1, 'b' => ['x', 'y']]) === null);
$page = DataExport::html('Expenses <1>', ['columns' => ['date', 'note'], 'rows' => [['2026-10-01', '<script>x</script>']]], ['Date' => '23.10.2026']);
check('printable page: heading, labelled columns, escaped cells', str_contains($page, '<h1>Expenses &lt;1&gt;</h1>') && str_contains($page, '<th>Date</th>') && str_contains($page, '&lt;script&gt;') && !str_contains($page, '<script>x'));

echo "7) the tool (SQLite, through a registry)\n";
if (!extension_loaded('pdo_sqlite')) { echo "  (pdo_sqlite missing — skipped)\n"; } else {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT, source TEXT DEFAULT 'ios_geofence', note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $ins = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (1, :t, :at, :loc)');
    foreach ([['in', '2026-10-13 05:48:00'], ['out', '2026-10-13 15:22:00'], ['in', '2026-10-14 06:20:00'], ['out', '2026-10-14 07:00:00'], ['in', '2026-10-14 07:30:00'], ['out', '2026-10-14 14:29:00']] as [$tt, $at]) {
        $ins->execute([':t' => $tt, ':at' => $at, ':loc' => 'Office']);
    }
    $reg = new App\Tools\ToolRegistry();
    $reg->register(new App\Tools\GetWorkHours(new App\Data\WorkEvents($db)));
    $tool = new App\Tools\ExportData();
    $tool->setRegistry($reg);
    $args = ['from' => '2026-10-01', 'to' => '2026-10-20', 'place' => 'Office'];
    $r = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'layout' => 'timesheet', 'customer' => 'Client Z'], 1);
    check('timesheet: days, total and the two links', ($r['summary']['days'] ?? 0) === 2 && ($r['summary']['total'] ?? '') === '17:13' && ($r['summary']['total_decimal'] ?? '') === '17,22', json_encode($r));
    check('links carry the whole spec (source, args, layout, customer) and the format, on the one endpoint', str_contains($r['csv_url'], '/api/export.php?spec=') && str_contains($r['csv_url'], 'format=csv') && str_contains($r['print_url'], 'format=html')
        && str_contains(rawurldecode($r['csv_url']), '"layout":"timesheet"') && str_contains(rawurldecode($r['csv_url']), '"customer":"Client Z"') && str_contains(rawurldecode($r['csv_url']), '"place":"Office"'));
    $spec = json_decode(rawurldecode(explode('&format=', substr($r['csv_url'], strpos($r['csv_url'], 'spec=') + 5))[0]), true);
    $b = App\Tools\ExportData::build($reg, $spec, 1);
    check('the endpoint rebuilds the same sheet from the link', $b['sheet']['total_minutes'] === 1033 && count($b['sheet']['days']) === 2);
    check('rounding in the timesheet layout', App\Tools\ExportData::build($reg, array_merge($spec, ['round' => 30]), 1)['sheet']['total_minutes'] === 1020);
    $rb = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'layout' => 'timesheet', 'break_minutes' => 30, 'break_after_hours' => 4], 1);
    check('tool: break deducted from both days (17:13 − 1:00)', ($rb['summary']['total'] ?? '') === '16:13' && ($rb['summary']['break_deducted_days'] ?? 0) === 2, json_encode($rb['summary'] ?? $rb));
    $bspec = json_decode(rawurldecode(explode('&format=', substr($rb['csv_url'], strpos($rb['csv_url'], 'spec=') + 5))[0]), true);
    check('the link carries the break', ($bspec['break'] ?? 0) === 30 && ($bspec['break_over'] ?? 0) === 240 && App\Tools\ExportData::build($reg, $bspec, 1)['sheet']['total_minutes'] === 973);
    $mis = $tool->execute(['source' => 'get_work_hours', 'args' => $args + ['break_minutes' => 30, 'break_after_hours' => 4, 'customer' => 'Client Z'], 'layout' => 'timesheet'], 1);
    $mspec = json_decode(rawurldecode(explode('&format=', substr($mis['csv_url'], strpos($mis['csv_url'], 'spec=') + 5))[0]), true);
    check('options the model put inside args still count, and are not passed on to the source tool', ($mis['summary']['total'] ?? '') === '16:13' && ($mspec['break'] ?? 0) === 30 && ($mspec['customer'] ?? '') === 'Client Z' && !isset($mspec['args']['break_minutes']), json_encode($mspec));
    $fn = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'layout' => 'timesheet', 'from_name' => 'Studio X', 'title' => 'Hours', 'signature' => true], 1);
    $fspec = json_decode(rawurldecode(explode('&format=', substr($fn['csv_url'], strpos($fn['csv_url'], 'spec=') + 5))[0]), true);
    check('from_name, title and signature travel in the link; signature defaults off', ($fspec['from_name'] ?? '') === 'Studio X' && ($fspec['title'] ?? '') === 'Hours' && ($fspec['signature'] ?? false) === true && (json_decode(rawurldecode(explode('&format=', substr($mis['csv_url'], strpos($mis['csv_url'], 'spec=') + 5))[0]), true)['signature'] ?? null) === false);
    check('no enum on integers anywhere in the schema (Gemini accepts only string enums)', !preg_match('/"enum":\[\d/', json_encode($tool->parameters())));
    $tbl = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'columns' => ['date', 'in', 'out', 'minutes']], 1);
    check('table layout: one row per clocked session with the chosen columns', ($tbl['summary']['rows'] ?? 0) === 3 && ($tbl['summary']['columns'] ?? []) === ['date', 'in', 'out', 'minutes'], json_encode($tbl));
    check('nothing in the period → a clear error, not an empty file', isset($tool->execute(['source' => 'get_work_hours', 'args' => ['from' => '2025-01-01', 'to' => '2025-01-02'], 'layout' => 'timesheet'], 1)['error']));
    check('only whitelisted read tools; timesheet only for work hours', isset($tool->execute(['source' => 'delete_work_event'], 1)['error']) && isset($tool->execute(['source' => 'get_expenses', 'layout' => 'timesheet'], 1)['error']));
    check('another user sees none of this user\'s hours', isset($tool->execute(['source' => 'get_work_hours', 'args' => $args, 'layout' => 'timesheet'], 2)['error']));
    check('the description names the sources and the timesheet layout', str_contains($tool->description(), 'get_expenses') && str_contains($tool->description(), 'timeseddel'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
