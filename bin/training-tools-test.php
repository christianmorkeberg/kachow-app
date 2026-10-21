<?php

declare(strict_types=1);

/** The training-programme tools and their input handling, against in-memory SQLite. No network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\TrainingPrograms;
use App\Support\ProgramCard;
use App\Support\ProgramInput;
use App\Tools\AddProgramDays;
use App\Tools\DeleteTrainingProgram;
use App\Tools\GetTrainingProgram;
use App\Tools\RemoveProgramDays;
use App\Tools\SaveTrainingProgram;
use App\Tools\UpdateTrainingProgram;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "reading what a model sends\n";
check('weekdays: numbers, English, Danish, short forms', ProgramInput::weekday(3) === 3 && ProgramInput::weekday('3') === 3 && ProgramInput::weekday('Onsdag') === 3 && ProgramInput::weekday('wed.') === 3 && ProgramInput::weekday('lørdag') === 6 && ProgramInput::weekday('Søn') === 7 && ProgramInput::weekday(8) === null && ProgramInput::weekday('someday') === null);
check('weeks: 43, "43", "uge 43", "week 43", "w43"', ProgramInput::week(43) === 43 && ProgramInput::week('43') === 43 && ProgramInput::week('uge 43') === 43 && ProgramInput::week('Week 43') === 43 && ProgramInput::week('w43') === 43 && ProgramInput::week(0) === null && ProgramInput::week('later') === null);
$now = new DateTimeImmutable('2026-10-21 09:00:00', new DateTimeZone('Europe/Copenhagen'));
check('a calendar week without a year: this year, or next when it is far behind', ProgramInput::inferYear(43, $now) === 2026 && ProgramInput::inferYear(44, $now) === 2026 && ProgramInput::inferYear(5, $now) === 2027 && ProgramInput::inferYear(40, $now) === 2026);
check('an item reads as one line', ProgramInput::itemLabel(['exercise' => 'Deadlift', 'sets' => 1, 'reps' => 4, 'weight' => 117.0, 'note' => null]) === 'Deadlift 1×4 @ 117 kg'
    && ProgramInput::itemLabel(['exercise' => 'Lat pulldown', 'sets' => null, 'reps' => null, 'weight' => null, 'note' => 'alt: pull-ups']) === 'Lat pulldown — alt: pull-ups' && ProgramInput::itemLabel(['exercise' => 'Squat', 'sets' => 5, 'reps' => null, 'weight' => 82.5, 'note' => null]) === 'Squat 5 sets @ 82.5 kg');
check('a note that is a programme is recognised; ordinary facts are not', ProgramInput::looksLikeProgramme("Uge 43: Mandag: bænkpres 5x5 80 kg, row 3x8. Onsdag: Dødløft 117 kg x 4, skulderpres, lat pulldown. Fredag: squat 5x5")
    && ProgramInput::looksLikeProgramme("Træningsprogram uge 44 — onsdag: dødløft, skulderpres, lunge") && !ProgramInput::looksLikeProgramme('Alex is allergic to shellfish and works as a nurse') && !ProgramInput::looksLikeProgramme('Prefers mornings. Works on Monday and Tuesday at the office'));

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE training_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, name TEXT, status TEXT DEFAULT 'active', week_mode TEXT DEFAULT 'calendar', start_date TEXT, repeat_weeks INT, source_note TEXT, created_at TEXT, updated_at TEXT)");
$db->exec('CREATE TABLE training_program_days (id INTEGER PRIMARY KEY AUTOINCREMENT, program_id INT, week_no INT, year INT DEFAULT 0, weekday INT, title TEXT, note TEXT, UNIQUE (program_id, year, week_no, weekday))');
$db->exec('CREATE TABLE training_program_items (id INTEGER PRIMARY KEY AUTOINCREMENT, day_id INT, position INT, exercise TEXT, sets INT, reps INT, weight REAL, note TEXT)');
$P = new TrainingPrograms($db);
$save = new SaveTrainingProgram($P); $add = new AddProgramDays($P); $get = new GetTrainingProgram($P); $upd = new UpdateTrainingProgram($P); $rm = new RemoveProgramDays($P); $del = new DeleteTrainingProgram($P);

echo "building a programme from a note\n";
$r = $save->execute(['name' => 'Autumn', 'source_note' => 'uge 43 …'], 1);
check('save_training_program creates it (calendar by default)', isset($r['id']) && $r['week_mode'] === 'calendar');
check('the same name again returns it, it does not duplicate', ($save->execute(['name' => 'autumn'], 1)['exists'] ?? false) === true && $P->countAll(1) === 1);
check('a relative programme needs a start date', isset($save->execute(['name' => 'Rel', 'week_mode' => 'relative'], 1)['error']) && isset($save->execute(['name' => 'Rel', 'week_mode' => 'relative', 'start_date' => '2026-10-05', 'repeat_weeks' => 4], 1)['id']));
$days = [
    ['week' => 43, 'year' => 2026, 'weekday' => 'onsdag', 'title' => 'Pull', 'exercises' => [['exercise' => 'Dødløft', 'sets' => 1, 'reps' => 4, 'weight' => 117], ['exercise' => 'Lat pulldown', 'note' => 'eller pullups']]],
    ['week' => 'uge 43', 'year' => 2026, 'weekday' => 1, 'exercises' => [['exercise' => 'Bænkpres', 'sets' => 5, 'reps' => 5, 'weight' => 80]]],
    ['week' => 44, 'year' => 2026, 'weekday' => 'fredag', 'exercises' => []],
    ['week' => 'later', 'weekday' => 1, 'exercises' => [['exercise' => 'X']]],
    ['week' => 44, 'year' => 2026, 'weekday' => 3, 'exercises' => [['exercise' => 'Squat']]],
];
$r = $add->execute(['program' => 'Autumn', 'days' => $days], 1);
check('add_program_days saves the good days and says exactly why it skipped the others', $r['days_saved'] === 3 && $r['exercises_saved'] === 4 && count($r['skipped']) === 2, json_encode($r['skipped'] ?? null));
check('…and reports what the programme now covers', $r['programme_now'] === ['days' => 3, 'weeks' => '43–44']);
$r2 = $add->execute(['program' => 'Autumn', 'days' => [['week' => 43, 'year' => 2026, 'weekday' => 3, 'title' => 'Pull v2', 'exercises' => [['exercise' => 'Dødløft', 'sets' => 1, 'reps' => 5, 'weight' => 120]]]]], 1);
check('sending a day again replaces it', $r2['days_replaced'] === 1 && $P->days(1, 1, 43)[1]['items'][0]['weight'] === 120.0 && $P->days(1, 1, 43)[1]['title'] === 'Pull v2');
$yr = $add->execute(['program' => 'Autumn', 'days' => [['week' => 46, 'weekday' => 2, 'exercises' => [['exercise' => 'Curl']]]]], 1);
check('without a year a calendar week is pinned to the right one', $yr['days_saved'] === 1 && $P->days(1, 1, 46)[0]['year'] === 2026);
check('an unknown programme or an empty call is an error', isset($add->execute(['program' => 'nope', 'days' => $days], 1)['error']) && isset($add->execute(['program' => 'Autumn', 'days' => []], 1)['error']));
check('days over the limit are refused, not half-saved', isset($add->execute(['program' => 'Autumn', 'days' => array_fill(0, 61, $days[0])], 1)['error']));
check('another user cannot add to it', isset($add->execute(['program' => 'Autumn', 'days' => $days], 2)['error']));

echo "showing and changing it\n";
$g = $get->execute([], 1);
check('get_training_program lists them and shows a card', count($g['programs']) === 2 && ($g['_render']['kind'] ?? '') === 'training_program');
$g = $get->execute(['program' => 'autumn', 'week' => 'uge 43'], 1);
check('one week comes back in full, as lines', $g['week'] === 43 && count($g['days']) === 2 && $g['days'][0]['exercises'][0] === 'Bænkpres 5×5 @ 80 kg' && $g['days'][1]['exercises'][0] === 'Dødløft 1×5 @ 120 kg');
$card = $g['_render'];
check('the card has weeks, days with names, and where today falls', count($card['weeks']) === 3 && $card['weeks'][0]['days'][0]['name'] === 'Monday' && array_key_exists('week', $card['today']));
check('nothing saved → a hint to offer saving one', str_contains((new GetTrainingProgram(new TrainingPrograms($db)))->execute([], 9)['note'], 'save_training_program'));
check('rename and archive', $upd->execute(['program' => 'autumn', 'name' => 'Autumn 2026', 'status' => 'archived'], 1)['programme']['status'] === 'archived' && $P->find(1, 'Autumn 2026') !== null);
check('an archived programme prescribes nothing', $P->dayFor(1, '2026-10-21') === null);
$upd->execute(['program' => 'Autumn 2026', 'status' => 'active'], 1);
check('active again', $P->dayFor(1, '2026-10-21')['title'] === 'Pull v2');
check('remove one weekday, then a week', $rm->execute(['program' => 'Autumn 2026', 'week' => 43, 'weekday' => 'mandag'], 1)['removed_days'] === 1 && $rm->execute(['program' => 'Autumn 2026', 'week' => 44], 1)['removed_days'] === 1);
check('removing needs a week or a weekday', isset($rm->execute(['program' => 'Autumn 2026'], 1)['error']));
check('delete the programme (and not someone else\'s)', ($del->execute(['program' => 'Autumn 2026'], 2)['error'] ?? '') !== '' && $del->execute(['program' => 'Autumn 2026'], 1)['deleted'] === true && $P->find(1, 'Autumn 2026') === null);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
