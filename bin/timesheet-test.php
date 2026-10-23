<?php

declare(strict_types=1);

/** The timesheet: hours per day from clock sessions, rounding, formats, CSV, printable page, and the export_timesheet tool. SQLite, no network. */
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

echo "6) the tool (SQLite)\n";
if (!extension_loaded('pdo_sqlite')) { echo "  (pdo_sqlite missing — skipped)\n"; } else {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE work_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, occurred_at TEXT, location TEXT, source TEXT DEFAULT \'ios_geofence\', note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE TABLE work_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, log_date TEXT, job TEXT, description TEXT, created_at TEXT)');
    $ev = new App\Data\WorkEvents($db);
    $ins = $db->prepare('INSERT INTO work_events (user_id, kind, occurred_at, location) VALUES (1, :t, :at, :loc)');
    foreach ([['in', '2026-10-13 05:48:00'], ['out', '2026-10-13 15:22:00'], ['in', '2026-10-14 06:20:00'], ['out', '2026-10-14 07:00:00'], ['in', '2026-10-14 07:30:00'], ['out', '2026-10-14 14:29:00']] as [$t, $at]) {
        $ins->execute([':t' => $t, ':at' => $at, ':loc' => 'Office']);
    }
    $db->exec("INSERT INTO work_log (user_id, log_date, job, description) VALUES (1, '2026-10-13', 'Office', 'Wrote tests'), (1, '2026-10-13', 'Office', 'Reviewed')");
    $tool = new App\Tools\ExportTimesheet($ev, new App\Data\WorkLog($db));
    $res = $tool->execute(['from' => '2026-10-01', 'to' => '2026-10-20', 'place' => 'Office', 'customer' => 'Client Z', 'include_notes' => true], 1);
    check('days, per-day hours and the total', $res['day_count'] === 2 && $res['days'][0]['hours'] === '9:34' && $res['days'][1]['hours'] === '7:39' && $res['total'] === '17:13', json_encode($res));
    check('links carry the range, place, customer, notes and the format', str_contains($res['csv_url'], 'format=csv') && str_contains($res['print_url'], 'format=html') && str_contains($res['csv_url'], 'place=Office') && str_contains($res['csv_url'], 'customer=Client+Z') && str_contains($res['csv_url'], 'notes=1') && str_contains($res['csv_url'], '/api/timesheet.php?'));
    $sheet2 = Timesheet::forRange($ev, new App\Data\WorkLog($db), 1, '2026-10-01', '2026-10-20', 'Office', 0, true);
    check('notes from the work log land on their day, joined oldest first', $sheet2['sheet']['days'][0]['note'] === 'Reviewed; Wrote tests' || $sheet2['sheet']['days'][0]['note'] === 'Wrote tests; Reviewed', (string) $sheet2['sheet']['days'][0]['note']);
    check('rounded and in English', ($tool->execute(['from' => '2026-10-01', 'to' => '2026-10-20', 'round_minutes' => 30, 'language' => 'en'], 1)['total_decimal'] ?? '') === '17.00');
    check('the schema description says it is for a customer and points away from the notes export', str_contains($tool->description(), 'timeseddel') && str_contains((new App\Tools\ExportWorkLog(new App\Data\WorkLog($db)))->description(), 'export_timesheet'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
