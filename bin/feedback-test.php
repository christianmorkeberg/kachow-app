<?php

declare(strict_types=1);

/**
 * Feedback reports: resolving one (even a stale card resolving an already-resolved report) and the list the
 * card's refresh re-reads. In-memory SQLite whose statements report "0 rows changed" for EVERY update — the
 * worst case of MySQL's no-op UPDATE behaviour — so nothing here may depend on rowCount():
 *   php -d extension=php_pdo_sqlite bin/feedback-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\FeedbackReports;
use App\Tools\ListFeedback;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/feedback-test.php\n");
    exit(2);
}

/** Statement that never reports affected rows, like MySQL for an UPDATE that changes nothing. */
final class ZeroRowCount extends PDOStatement
{
    public function rowCount(): int
    {
        return 0;
    }
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
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ZeroRowCount::class]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$db->exec('CREATE TABLE feedback_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, conversation_id INT, message_id INT, note TEXT,
    snapshot TEXT, status TEXT DEFAULT "new", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec("INSERT INTO users (id, name, email) VALUES (1, 'Reporter', 'r@example.test')");
$reports = new FeedbackReports($db);

$snap = static fn (string $text): array => ['reported' => ['role' => 'assistant', 'content' => $text, 'diagnostics' => ['model' => 'm']], 'context' => [['role' => 'user', 'content' => 'hi']]];
$a = $reports->create(1, 10, 100, 'first', $snap('one'));
$b = $reports->create(1, 11, 101, 'second', $snap('two'));
$c = $reports->create(1, 12, 102, null, $snap('three'));

echo "1) resolving\n";
check('a new report can be resolved', $reports->setStatus($a, 'resolved') === true && $reports->find($a)['status'] === 'resolved');
check('resolving it AGAIN still succeeds (a stale card — this used to read "No such report")', $reports->setStatus($a, 'resolved') === true && $reports->find($a)['status'] === 'resolved');
check('an unknown report is still reported as missing', $reports->setStatus(9999, 'resolved') === false);
check('an invalid status is refused and changes nothing', $reports->setStatus($b, 'bogus') === false && $reports->find($b)['status'] === 'new');
check('it can be reopened, and "seen" works', $reports->setStatus($a, 'new') === true && $reports->find($a)['status'] === 'new'
    && $reports->setStatus($a, 'seen') === true && $reports->find($a)['status'] === 'seen');
$reports->setStatus($a, 'resolved');

echo "\n2) the list the card is built from (and re-read by its refresh button)\n";
$new = ListFeedback::build($reports, 'new');
check('the open list leaves out the resolved one', array_column($new['reports'], 'id') === [$c, $b], json_encode(array_column($new['reports'], 'id')));
check('the card knows its filter, page size and open total', $new['card']['kind'] === 'feedback' && $new['card']['status'] === 'new'
    && $new['card']['limit'] === 20 && $new['card']['new_total'] === 2);
check('resolved list', array_column(ListFeedback::build($reports, 'resolved')['reports'], 'id') === [$a]);
check('all', count(ListFeedback::build($reports, 'all')['reports']) === 3);
check('an unknown status falls back to the open list', ListFeedback::build($reports, 'nonsense')['card']['status'] === 'open');
check('the page size is kept', count(ListFeedback::build($reports, 'all', 2)['reports']) === 2 && ListFeedback::build($reports, 'all', 2)['card']['limit'] === 2);
$one = ListFeedback::build($reports, 'new')['reports'][1];
check('each report carries what the card shows', $one['note'] === 'second' && $one['from'] === 'Reporter' && $one['reported_text'] === 'two'
    && $one['model'] === 'm' && count($one['conversation']) === 1 && ($one['conversation'][0]['reported'] ?? false) === true);

echo "\n3) a refresh after resolving elsewhere\n";
$reports->setStatus($b, 'resolved');   // e.g. from the chat tool or another device
check('the open list no longer shows it', array_column(ListFeedback::build($reports, 'new')['reports'], 'id') === [$c]);
check('and the open total follows', ListFeedback::build($reports, 'new')['card']['new_total'] === 1);

echo "\n4) open first, filters and the chip counts\n";
$d = $reports->create(1, 13, 103, 'fourth', $snap('four'));   // newer than the resolved ones
$reports->setStatus($a, 'new');                                 // reopen the oldest
$reports->setStatus($c, 'resolved');
// states now: a new (oldest), b resolved, c resolved, d new (newest)
$all = array_column(ListFeedback::build($reports, 'all')['reports'], 'id');
check('"all" lists unresolved first (newest first), then resolved (newest first)', $all === [$d, $a, $c, $b], json_encode($all));
$open = ListFeedback::build($reports, 'open');
check('"open" is everything not resolved', array_column($open['reports'], 'id') === [$d, $a]);
$reports->setStatus($a, 'seen');
check('"open" includes seen reports, "new" does not', array_column(ListFeedback::build($reports, 'open')['reports'], 'id') === [$d, $a]
    && array_column(ListFeedback::build($reports, 'new')['reports'], 'id') === [$d]);
check('the default is the open list', ListFeedback::build($reports)['card']['status'] === 'open');
check('the card carries the chip counts', ListFeedback::build($reports, 'open')['card']['counts'] === ['open' => 2, 'resolved' => 2, 'all' => 4],
    json_encode(ListFeedback::build($reports, 'open')['card']['counts']));
check('the limit cuts the resolved tail, never the open ones', array_column(ListFeedback::build($reports, 'all', 2)['reports'], 'id') === [$d, $a]);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
