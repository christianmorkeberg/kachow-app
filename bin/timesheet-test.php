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
$en = Timesheet::csvRows(Timesheet::build($sessions, 0, ['2026-10-13' => 'Wrote tests']), 'en');
check('English with a notes column', $en[0][5] === 'Description' && $en[1][1] === 'Tuesday' && $en[1][4] === '9.57' && count($en[1]) === 6);
$notes = Timesheet::build($sessions, 0, ['2026-10-13' => ' Migration work ', '2026-10-14' => '']);
check('notes are trimmed and empty ones dropped', $notes['days'][0]['note'] === 'Migration work' && $notes['days'][1]['note'] === null);

echo "5) printable page\n";
$html = Timesheet::html(Timesheet::build($sessions, 15, ['2026-10-13' => '<b>x</b>']), 'da', ['person' => 'Alex <script>', 'customer' => 'Client Z & Co', 'job' => 'Office', 'period' => '01.10.2026 – 23.10.2026', 'made' => '23.10.2026']);
check('a standalone page with the title, the people and the period', str_starts_with($html, '<!doctype html>') && str_contains($html, '<h1>Timeseddel</h1>') && str_contains($html, 'Client Z &amp; Co') && str_contains($html, '01.10.2026'));
check('everything user-supplied is escaped', !str_contains($html, '<script>') && !str_contains($html, '<b>x</b>') && str_contains($html, 'Alex &lt;script&gt;') && str_contains($html, '&lt;b&gt;x&lt;/b&gt;'));
check('dates, hours, total, rounding note and a print button that is hidden when printing', str_contains($html, '13.10.2026') && str_contains($html, '9:30') && str_contains($html, '22:00') && str_contains($html, 'nærmeste 15 min') && str_contains($html, '@media print{.bar{display:none}'));
$enHtml = Timesheet::html(Timesheet::build($sessions), 'en', []);
check('English page, no notes column, no rounding note', str_contains($enHtml, '<h1>Timesheet</h1>') && !str_contains($enHtml, 'Description') && !str_contains($enHtml, 'rounded to'));

$plain = Timesheet::html(Timesheet::build($sessions), 'da', ['title' => 'Timeseddel – Studio X', 'person' => 'Studio X', 'made' => '23.10.2026']);
check('a custom heading replaces the default (also in <title>) and the sender name is printed', str_contains($plain, '<h1>Timeseddel – Studio X</h1>') && str_contains($plain, '<title>Timeseddel – Studio X</title>') && str_contains($plain, '<span>Navn</span> Studio X'));
check('no signature block by default, one when asked', !str_contains($plain, 'class="sign"') && !str_contains($plain, 'Underskrift') && str_contains(Timesheet::html(Timesheet::build($sessions), 'da', [], ['signature' => true]), 'Underskrift'));

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

echo "6b) shaping a table\n";
$exp = ['items' => [
    ['date' => '2026-10-01', 'category' => 'travel', 'amount' => '100'], ['date' => '2026-10-09', 'category' => 'food', 'amount' => '40.5'],
    ['date' => '2026-10-15', 'category' => 'travel', 'amount' => '60'], ['date' => '2026-11-02', 'category' => 'travel', 'amount' => '10'],
]];
$base = DataExport::table($exp);
$plain = DataExport::shape($base);
check('no options → the table as it was, no total', $plain['rows'] === $base['rows'] && $plain['total'] === null && $plain['labels'] === ['Date', 'Category', 'Amount']);
$f = DataExport::shape($base, ['where' => ['category' => 'TRAVEL'], 'sort_by' => 'amount', 'sort_desc' => true, 'labels' => ['amount' => 'Beløb'], 'limit' => 2]);
check('filter (case-insensitive), numeric sort descending, renamed heading, limit', array_column($f['rows'], 2) === ['100', '60'] && $f['labels'][2] === 'Beløb', json_encode($f['rows']));
$t = DataExport::shape($base, ['total_columns' => ['amount']], 'da');
check('a total row sums the chosen columns (decimal comma in Danish) and labels itself in the first free column', $t['total'] === ['I alt', '', '210,5'], json_encode($t['total']));
check('…in English with a point; show_total false removes it', DataExport::shape($base, ['total_columns' => ['amount']], 'en')['total'][2] === '210.5' && DataExport::shape($base, ['total_columns' => ['amount'], 'total' => false])['total'] === null);
$c = DataExport::shape($base, ['calc' => [['label' => 'Incl. VAT', 'column' => 'amount', 'multiply' => 1.25]], 'total_columns' => ['calc_1']], 'en');
check('a calculated column (column × 1.25), labelled, and summable', $c['columns'][3] === 'calc_1' && $c['labels'][3] === 'Incl. VAT' && $c['rows'][0][3] === '125' && $c['total'][3] === '263.13', json_encode([$c['rows'][0], $c['total']]));
$h = DataExport::shape(['columns' => ['minutes'], 'rows' => [['450'], ['90']]], ['calc' => [['label' => 'Amount', 'column' => 'minutes', 'divide' => 60, 'multiply' => 650]]], 'da');
check('minutes → hours × rate', $h['rows'][0][1] === '4875' && $h['rows'][1][1] === '975');
$g = DataExport::shape($base, ['group_by' => 'date', 'group_period' => 'month', 'total_columns' => ['amount']], 'en');
check('group by month: one row per month with a count and the sum, and a grand total', $g['columns'] === ['date', 'count', 'amount'] && $g['rows'] === [['2026-10', '3', '200.5'], ['2026-11', '1', '10']] && $g['total'] === ['Total', '4', '210.5'], json_encode($g));
$gw = DataExport::shape($base, ['group_by' => 'date', 'group_period' => 'week'], 'en');
check('group by ISO week (1 Oct is week 40, 9 Oct week 41) — no sums → just counts, no total row', $gw['rows'][0][0] === '2026-W40' && $gw['rows'][1][0] === '2026-W41' && $gw['total'] === null, json_encode($gw['rows']));
$gc = DataExport::shape($base, ['group_by' => 'category', 'total_columns' => ['amount']], 'en');
check('group by a plain column', $gc['rows'] === [['food', '1', '40.5'], ['travel', '3', '170']], json_encode($gc['rows']));
check('unknown columns in every option are ignored, not fatal', DataExport::shape($base, ['where' => ['nope' => 'x'], 'sort_by' => 'nope', 'group_by' => 'nope', 'total_columns' => ['nope'], 'calc' => [['column' => 'nope']]])['rows'] === $base['rows']);
$page = DataExport::html('Statement', ['columns' => $g['columns'], 'labels' => ['Month', 'Items', 'Amount'], 'rows' => $g['rows'], 'total' => $g['total']], ['Customer' => 'Client Z'], "Thanks\nBest", 'en');
check('printed table: renamed headings, total row in the foot, header lines and a footer note, English button', str_contains($page, '<th>Month</th>') && str_contains($page, '<tfoot><tr><td>Total</td>') && str_contains($page, '<span>Customer</span> Client Z') && str_contains($page, 'Thanks<br />') && str_contains($page, 'Print / save as PDF'));

echo "6c) choosing timesheet columns\n";
$only = Timesheet::csvRows($sheet, 'da', ['show' => ['hours'], 'labels' => ['date' => 'Dag', 'hours' => 'Tid', 'total' => 'Sum']]);
check('show only some columns, rename headings and the total label', $only[0] === ['Dag', 'Tid'] && $only[1] === ['2026-10-13', '9:34'] && $only[4][0] === 'Sum', json_encode($only[0]));
check('no total row when asked', count(Timesheet::csvRows($sheet, 'da', ['total' => false])) === 4);
check('break column on demand even when none was deducted', Timesheet::csvRows($sheet, 'da', ['show' => ['break', 'hours']])[1] === ['2026-10-13', '–', '9:34']);
$ph = Timesheet::html($sheet, 'da', ['extra' => ['Faktura' => '12']], ['show' => ['times', 'hours'], 'footer' => 'Tak', 'total' => false]);
check('printed timesheet: chosen columns only, extra header line, footer, no foot', !str_contains($ph, '<th>Ugedag</th>') && str_contains($ph, '<th>Tidspunkter</th>') && str_contains($ph, '<span>Faktura</span> 12') && str_contains($ph, '>Tak</p>') && !str_contains($ph, '<tfoot>'));

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
    check('from_name, title and signature travel in the link; signature defaults off', ($fspec['from_name'] ?? '') === 'Studio X' && ($fspec['title'] ?? '') === 'Hours' && ($fspec['signature'] ?? false) === true && (json_decode(rawurldecode(explode('&format=', substr($mis['csv_url'], strpos($mis['csv_url'], 'spec=') + 5))[0]), true)['signature'] ?? false) === false);
    $opt = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'group_by' => 'date', 'total_columns' => ['minutes'], 'labels' => ['minutes' => 'Min'], 'header' => ['Customer' => 'Client Z'], 'footer' => 'Thanks', 'show_total' => true,
        'calc' => [['label' => 'Amount', 'column' => 'minutes', 'divide' => 60, 'multiply' => 650]]], 1);
    check('tool: grouped table with sums; the preview names the columns the options refer to', ($opt['summary']['rows'] ?? 0) === 2 && in_array('minutes', $opt['summary']['source_columns'] ?? [], true) && ($opt['summary']['total_row'][2] ?? '') === '1033', json_encode($opt['summary'] ?? $opt));
    $ospec = json_decode(rawurldecode(explode('&format=', substr($opt['csv_url'], strpos($opt['csv_url'], 'spec=') + 5))[0]), true);
    check('all the options travel in the link, empty ones do not', ($ospec['group_by'] ?? '') === 'date' && ($ospec['header']['Customer'] ?? '') === 'Client Z' && ($ospec['footer'] ?? '') === 'Thanks' && ($ospec['calc'][0]['multiply'] ?? 0) == 650 && !isset($ospec['where']) && !isset($ospec['sort_by']));
    $ofalse = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'show_total' => false, 'total_columns' => ['minutes']], 1);
    check('show_total false is kept in the link (it is not "empty")', str_contains(rawurldecode($ofalse['csv_url']), '"total":false') && $ofalse['summary']['total_row'] === null);
    check('a filter that matches nothing is a clear error', isset($tool->execute(['source' => 'get_work_hours', 'args' => $args, 'where' => ['place' => 'Nowhere']], 1)['error']));
    $ts = $tool->execute(['source' => 'get_work_hours', 'args' => $args, 'layout' => 'timesheet', 'show_columns' => ['hours'], 'labels' => ['hours' => 'Tid'], 'show_total' => false, 'header' => ['Faktura' => '12']], 1);
    $tspec = json_decode(rawurldecode(explode('&format=', substr($ts['csv_url'], strpos($ts['csv_url'], 'spec=') + 5))[0]), true);
    $tb = App\Tools\ExportData::build($reg, $tspec, 1);
    check('timesheet options (columns, labels, no total) reach the sheet options', $tb['options']['show'] === ['hours'] && $tb['options']['labels'] === ['hours' => 'Tid'] && $tb['options']['total'] === false);
    $lift = $tool->execute(['source' => 'get_work_hours', 'args' => $args + ['group_by' => 'date', 'footer' => 'Hi']], 1);
    check('table options the model put inside args are lifted out', str_contains(rawurldecode($lift['csv_url']), '"group_by":"date"') && !str_contains(rawurldecode($lift['csv_url']), '"args":{"from":"2026-10-01","to":"2026-10-20","place":"Office","group_by"'));
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
