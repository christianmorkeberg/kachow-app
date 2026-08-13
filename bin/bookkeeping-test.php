<?php

declare(strict_types=1);

/**
 * Bookkeeping integration self-test. Runs the REAL Data classes + tools against an
 * in-memory SQLite DB (injected via each class's optional ?PDO constructor), so it
 * exercises income / owner-draws / udlæg / audit end to end WITHOUT a MySQL server and
 * WITHOUT persisting anything — the DB lives only for this process.
 *
 *   php -d extension=php_pdo_sqlite bin/bookkeeping-test.php
 *
 * (The pdo_sqlite driver ships with PHP but is often disabled in php.ini; the -d flag
 * enables it just for this run. Exit 0 = pass, 1 = a failed assertion, 2 = can't run.)
 *
 * Complements bin/routing-test.php. Run after touching Income/OwnerDraws/Receipts or
 * the bookkeeping tools; add a case when a real bug slips through.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\BookkeepingAudit;
use App\Data\Income;
use App\Data\OwnerDraws;
use App\Data\Receipts;
use App\Tools\AddExpense;
use App\Tools\AddIncome;
use App\Tools\AddOwnerDraw;
use App\Tools\GetIncome;
use App\Tools\GetOwnerDraws;
use App\Tools\MarkExpenseReimbursed;
use App\Tools\MarkInvoicePaid;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/bookkeeping-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}
function money($n): string { return number_format((float) $n, 2, '.', ''); }

// ---- in-memory schema (SQLite dialect of migrations/2026-08-13_bookkeeping.sql) ----
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE income (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    kind TEXT DEFAULT 'invoice', source TEXT DEFAULT 'manual', status TEXT DEFAULT 'draft',
    doc_number TEXT, customer TEXT, issued_at TEXT, paid_at TEXT,
    amount_ex_vat NUMERIC, vat NUMERIC, total NUMERIC, currency TEXT DEFAULT 'DKK',
    category TEXT, note TEXT, file_ref TEXT, mime TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE owner_draws (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    drawn_at TEXT NOT NULL, amount NUMERIC NOT NULL, currency TEXT DEFAULT 'DKK', note TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE bookkeeping_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, action TEXT NOT NULL,
    detail TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    source TEXT DEFAULT 'manual', status TEXT DEFAULT 'draft', file_ref TEXT, mime TEXT,
    vendor TEXT, purchased_at TEXT, total NUMERIC, vat NUMERIC, currency TEXT DEFAULT 'DKK',
    category TEXT, note TEXT, line_items TEXT, paid_privately INTEGER NOT NULL DEFAULT 0,
    reimbursed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$U = 1;
$income  = new Income($db);
$draws   = new OwnerDraws($db);
$audit   = new BookkeepingAudit($db);
$receipt = new Receipts($db);

echo "\n== 1. VAT derivation (25% moms), pure math ==\n";
[$ex, $vat, $tot] = Income::deriveVat(null, null, 10000.0);          // total only
check('total 10000 → ex 8000 / vat 2000', money($ex) === '8000.00' && money($vat) === '2000.00', "ex={$ex} vat={$vat}");
[$ex, $vat, $tot] = Income::deriveVat(10000.0, null, null);          // ex only
check('ex 10000 → vat 2500 / total 12500', money($vat) === '2500.00' && money($tot) === '12500.00', "vat={$vat} total={$tot}");
[$ex, $vat, $tot] = Income::deriveVat(8000.0, 2000.0, null);         // ex + vat
check('ex 8000 + vat 2000 → total 10000', money($tot) === '10000.00', "total={$tot}");
[$ex, $vat, $tot] = Income::deriveVat(null, null, 5000.0, false);    // zero-rated
check('non-vatable 5000 → vat 0', money($vat) === '0.00', "vat={$vat}");

echo "\n== 2. add_income tool (invoice to the kommune, 10000 + moms) ==\n";
$addIncome = new AddIncome($income, $audit);
$r = $addIncome->execute(['amount_ex_vat' => 10000, 'customer' => 'Kommune X', 'source' => 'nemhandel', 'kind' => 'invoice'], $U);
$card = $r['_render'];
check('draft created', ($r['created'] ?? false) === true);
check('card ex=10000 vat=2500 total=12500',
    money($card['ex']) === '10000.00' && money($card['vat']) === '2500.00' && money($card['total']) === '12500.00',
    json_encode(['ex' => $card['ex'], 'vat' => $card['vat'], 'total' => $card['total']]));
check('unpaid by default', ($card['paid'] ?? true) === false);
$invId = $r['id'];
$income->book($U, $invId);

echo "\n== 3. second invoice (private client, VAT-inclusive 5000), then get_income ==\n";
$r2 = $addIncome->execute(['total' => 5000, 'customer' => 'Privat ApS', 'source' => 'private', 'paid' => true], $U);
$income->book($U, $r2['id']);
$getIncome = new GetIncome($income);
$g = $getIncome->execute(['period' => 'this_year'], $U);
$cur = $g['currencies'][0] ?? [];
check('2 invoices booked this year', ($g['count'] ?? 0) === 2, 'count=' . ($g['count'] ?? 0));
check('gross total = 17500 (12500 + 5000)', money($cur['total'] ?? 0) === '17500.00', money($cur['total'] ?? 0));
check('output VAT total = 3500 (2500 + 1000)', money($cur['vat'] ?? 0) === '3500.00', money($cur['vat'] ?? 0));
$out = $g['outstanding'][0] ?? [];
check('outstanding = 12500 (the unpaid kommune one)', money($out['total'] ?? 0) === '12500.00', money($out['total'] ?? 0));

echo "\n== 4. mark_invoice_paid → outstanding clears ==\n";
$markPaid = new MarkInvoicePaid($income, $audit);
$mp = $markPaid->execute(['id' => $invId], $U);
check('mark_invoice_paid ok', ($mp['ok'] ?? false) === true);
$g2 = $getIncome->execute(['period' => 'this_year'], $U);
check('no outstanding left', ($g2['outstanding'] ?? []) === [], json_encode($g2['outstanding'] ?? []));

echo "\n== 5. add_owner_draw (pay myself 15000) — must NOT touch income ==\n";
$addDraw = new AddOwnerDraw($draws, $audit);
$d = $addDraw->execute(['amount' => 15000], $U);
check('draw recorded', ($d['recorded'] ?? false) === true);
$getDraws = new GetOwnerDraws($draws);
$gd = $getDraws->execute(['period' => 'this_year'], $U);
check('draws total = 15000', money($gd['totals'][0]['total'] ?? 0) === '15000.00', money($gd['totals'][0]['total'] ?? 0));
$g3 = $getIncome->execute(['period' => 'this_year'], $U);
check('income unchanged by the draw (still 17500)', money($g3['currencies'][0]['total'] ?? 0) === '17500.00');

echo "\n== 6. udlæg: add_expense paid_privately, then reimburse ==\n";
$addExpense = new AddExpense($receipt);
$e = $addExpense->execute(['total' => 250, 'vendor' => 'Parking', 'vat' => 50, 'paid_privately' => true], $U);
$expId = $e['id'];
$receipt->confirm($U, $expId);   // only confirmed udlæg count as owed
$ud = $receipt->outstandingUdlaeg($U);
check('expense card flags paid_privately', ($e['_render']['paid_privately'] ?? false) === true);
check('outstanding udlæg = 250', money($ud['totals'][0]['total'] ?? 0) === '250.00', money($ud['totals'][0]['total'] ?? 0));
$markReimb = new MarkExpenseReimbursed($receipt, $audit);
$mr = $markReimb->execute(['id' => $expId], $U);
check('mark_expense_reimbursed ok', ($mr['ok'] ?? false) === true);
$ud2 = $receipt->outstandingUdlaeg($U);
check('no outstanding udlæg after reimburse', ($ud2['totals'] ?? []) === [], json_encode($ud2['totals'] ?? []));

echo "\n== 7. audit trail written (softer-immutability kontrolspor) ==\n";
$trail   = $audit->forEntity($U, 'income', $invId);
$actions = array_map(fn ($t) => $t['action'], $trail);
check('kommune invoice trail has create + paid', in_array('create', $actions, true) && in_array('paid', $actions, true), implode(',', $actions));
$recent = $audit->recent($U, 50);
check('audit has entries across entities', count($recent) >= 5, 'n=' . count($recent));

echo "\n== 8. owner-draw delete (test-content cleanup, trailed) ==\n";
$drawId = $gd['items'][0]['id'] ?? 0;
check('draw delete removes the row', $draws->delete($U, $drawId) === true);
$audit->log($U, 'draw', $drawId, 'delete');
$gd2 = $getDraws->execute(['period' => 'this_year'], $U);
check('draws empty after delete', ($gd2['count'] ?? -1) === 0, 'count=' . ($gd2['count'] ?? -1));

echo "\n== 9. invoice-number series helper (for generated private invoices) ==\n";
$next = $income->nextInvoiceNumber($U, 2026);
check('nextInvoiceNumber = K-2026-001', $next === 'K-2026-001', $next);

echo "\n---------------------------------------\n";
echo "Bookkeeping test: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
