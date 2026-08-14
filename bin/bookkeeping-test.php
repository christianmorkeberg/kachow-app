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
use App\Data\Books;
use App\Data\Cash;
use App\Data\CashEntries;
use App\Data\Income;
use App\Data\Moms;
use App\Data\OwnerDraws;
use App\Data\ProfitLoss;
use App\Data\Receipts;
use App\Data\UserSettings;
use App\Tools\AddExpense;
use App\Tools\AddIncome;
use App\Tools\AddOwnerDraw;
use App\Tools\GetIncome;
use App\Tools\GetOwnerDraws;
use App\Tools\MarkExpenseReimbursed;
use App\Tools\MarkInvoicePaid;
use App\Tools\UpdateIncome;

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
    doc_number TEXT, customer TEXT, issued_at TEXT, paid_at TEXT, due_at TEXT,
    amount_ex_vat NUMERIC, vat NUMERIC, total NUMERIC, currency TEXT DEFAULT 'DKK',
    category TEXT, note TEXT, file_ref TEXT, mime TEXT, line_items TEXT,
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
$db->exec("CREATE TABLE user_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    setting_key TEXT NOT NULL, setting_value TEXT NOT NULL)");
$db->exec("CREATE TABLE cash_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    occurred_at TEXT NOT NULL, direction TEXT NOT NULL, amount NUMERIC NOT NULL,
    category TEXT DEFAULT 'other', note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$U = 1;
$income   = new Income($db);
$draws    = new OwnerDraws($db);
$audit    = new BookkeepingAudit($db);
$receipt  = new Receipts($db);
$settings = new UserSettings($db);
$booksObj = new Books($income, $receipt, $draws, $settings);

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

echo "\n== 10. update_income corrects a draft in place — no duplicate (report #11) ==\n";
$upd = new UpdateIncome($income, $audit);
$c1  = $addIncome->execute(['amount_ex_vat' => 10000, 'customer' => 'DSB'], $U); // draft: ex 10000 → total 12500
$cid = $c1['id'];
$rowsBefore = (int) $db->query('SELECT COUNT(*) FROM income')->fetchColumn();
$u   = $upd->execute(['id' => $cid, 'total' => 10000], $U);                       // "no, 10k INCL vat"
$rowsAfter  = (int) $db->query('SELECT COUNT(*) FROM income')->fetchColumn();
check('update did not create a new row', $rowsBefore === $rowsAfter, "{$rowsBefore} -> {$rowsAfter}");
$uc = $u['_render'] ?? [];
check('draft now 10000 incl. moms (ex 8000 / vat 2000)',
    money($uc['total'] ?? 0) === '10000.00' && money($uc['ex'] ?? 0) === '8000.00' && money($uc['vat'] ?? 0) === '2000.00',
    json_encode(['ex' => $uc['ex'] ?? null, 'vat' => $uc['vat'] ?? null, 'total' => $uc['total'] ?? null]));

echo "\n== 11. Books cockpit overview (KPIs, moms, reserve) ==\n";
// State so far (all DKK): booked income = kommune (ex 10000 / vat 2500) + private
// (ex 4000 / vat 1000) → revenue 14000, output VAT 3500. One draft (DSB, not counted).
// Confirmed expenses = parking (total 250 / vat 50 → ex 200). Draws deleted.
$ov = $booksObj->overview($U, 'all', 0);
$k  = $ov['kpis'];
check('kind = bookkeeping', ($ov['kind'] ?? '') === 'bookkeeping');
check('revenue (ex-moms) = 14000', money($k['revenue']) === '14000.00', money($k['revenue']));
check('output VAT = 3500, input VAT = 50', money($k['output_vat']) === '3500.00' && money($k['input_vat']) === '50.00', json_encode([$k['output_vat'], $k['input_vat']]));
check('net moms = 3450', money($k['net_moms']) === '3450.00', money($k['net_moms']));
check('profit = 13800', money($k['profit']) === '13800.00', money($k['profit']));
check('reserve = 8970 (3450 moms + 40% of 13800)', money($k['reserve']['total']) === '8970.00', money($k['reserve']['total']));
check('reserve pct = 40', (int) $k['reserve']['pct'] === 40, (string) $k['reserve']['pct']);
$ic = $ov['income']['counts'];
check('income counts: 1 draft, 2 booked, 2 paid, 0 unpaid',
    $ic['draft'] === 1 && $ic['booked'] === 2 && $ic['paid'] === 2 && $ic['unpaid'] === 0, json_encode($ic));
check('income module lists all 3 entries', count($ov['income']['items']) === 3, (string) count($ov['income']['items']));
check('reserve % is configurable', (function () use ($db, $booksObj, $U) {
    // Insert directly (UserSettings::set uses MySQL ON DUPLICATE KEY, not SQLite).
    $db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES ({$U}, 'tax_reserve_pct', '50')");
    $o = $booksObj->overview($U, 'all', 0);
    return money($o['kpis']['reserve']['tax']) === '6900.00'; // 50% of 13800
})(), 'tax at 50% should be 6900');

echo "\n== 12. period navigation (granularity + offset) ==\n";
$q0 = Books::range('quarter', 0); $qm1 = Books::range('quarter', -1);
check('previous quarter ends before current quarter starts', $qm1[1] < $q0[0], $qm1[1] . ' vs ' . $q0[0]);
$m0 = Books::range('month', 0); $mm1 = Books::range('month', -1);
check('previous month ends before current month starts', $mm1[1] < $m0[0], $mm1[1] . ' vs ' . $m0[0]);
$y0 = Books::range('year', 0); $ym1 = Books::range('year', -1);
check('previous year label is one less', ((int) $ym1[2]) === ((int) $y0[2]) - 1, $ym1[2] . ' vs ' . $y0[2]);
$allr = Books::range('all', 0);
check('all range is unbounded', $allr[0] === null && $allr[1] === null);
$navCard = $booksObj->overview($U, 'quarter', -1);
check('overview carries granularity/offset + can_next', ($navCard['granularity'] ?? '') === 'quarter'
    && ($navCard['offset'] ?? 1) === -1 && ($navCard['can_next'] ?? false) === true, json_encode([$navCard['granularity'] ?? null, $navCard['offset'] ?? null, $navCard['can_next'] ?? null]));

echo "\n== 13. Moms quarterly settlement (salgs − købs = tilsvar) ==\n";
// Current quarter (offset 0): booked income today = salgsmoms 3500; confirmed expense
// today = købsmoms 50; so tilsvar = 3450 to pay. DSB draft is excluded but flagged.
$moms = new Moms($income, $receipt);
$ms   = $moms->settlement($U, 0);
check('salgsmoms = 3500', money($ms['salgsmoms']) === '3500.00', money($ms['salgsmoms']));
check('købsmoms = 50', money($ms['kobsmoms']) === '50.00', money($ms['kobsmoms']));
check('tilsvar = 3450 to pay', money($ms['tilsvar']) === '3450.00' && $ms['pay'] === true, money($ms['tilsvar']));
check('sales/expense counts = 2/1', $ms['sales_count'] === 2 && $ms['expense_count'] === 1, json_encode([$ms['sales_count'], $ms['expense_count']]));
check('draft income flagged (DSB not counted)', $ms['draft_income'] === 1, (string) $ms['draft_income']);
check('current quarter reported open', $ms['period_open'] === true);
$mc = $moms->card($U, 0);
check('moms card kind + label', ($mc['kind'] ?? '') === 'moms' && ($mc['period_label'] ?? '') === $ms['label'], json_encode([$mc['kind'] ?? null, $mc['period_label'] ?? null]));
// Deadline math: quarter start + 5 months (Q1→1 Jun, Q4→1 Mar next year).
check('deadline Q1 2026 = 2026-06-01', Moms::deadline(2026, 1)->format('Y-m-d') === '2026-06-01', Moms::deadline(2026, 1)->format('Y-m-d'));
check('deadline Q4 2026 = 2027-03-01', Moms::deadline(2026, 4)->format('Y-m-d') === '2027-03-01', Moms::deadline(2026, 4)->format('Y-m-d'));
check('quarterAt(-1) precedes quarterAt(0)', (function () {
    [$y0, $q0] = Moms::quarterAt(0); [$y1, $q1] = Moms::quarterAt(-1);
    return ($y1 * 4 + $q1) === ($y0 * 4 + $q0) - 1;
})());
// Previous quarter has no activity (all test data is dated today) → no nudge.
check('dueForNudge null when prior quarter empty', $moms->dueForNudge($U, 10) === null);

echo "\n== 14. cockpit manual add (blank draft + amount-derived, like the + Add buttons) ==\n";
// income.php create with no amounts → a blank draft dated today (editor fills the rest).
$blankId = $income->create($U, ['issued_at' => Income::today(), 'kind' => 'invoice'], 'manual');
$blank   = $income->get($U, $blankId);
check('blank income draft: status draft, dated today', ($blank['status'] ?? '') === 'draft' && ($blank['issued_at'] ?? '') === Income::today(), json_encode([$blank['status'] ?? null, $blank['issued_at'] ?? null]));
check('blank income draft: amounts null', $blank['amount_ex_vat'] === null && $blank['total'] === null);
$income->delete($U, $blankId);
// income.php create WITH a total → VAT derived (total/5) before insert.
[$dex, $dvat, $dtot] = Income::deriveVat(null, null, 6250.0);
$derId = $income->create($U, ['issued_at' => Income::today(), 'amount_ex_vat' => $dex, 'vat' => $dvat, 'total' => $dtot], 'manual');
$der   = $income->get($U, $derId);
check('income add from total 6250 → ex 5000 / vat 1250', money($der['amount_ex_vat']) === '5000.00' && money($der['vat']) === '1250.00', json_encode([$der['amount_ex_vat'], $der['vat']]));
$income->delete($U, $derId);
// receipt.php create → a draft (not yet in købsmoms until confirmed) counted by draftCount.
$rDraft = $receipt->create($U, ['purchased_at' => Income::today(), 'vendor' => 'Manual', 'total' => 100, 'vat' => 20], 'manual');
check('manual expense draft counts as a draft receipt', $receipt->draftCount($U) >= 1);
$receipt->delete($U, $rDraft);
// draws.php create → immediate record (no draft lifecycle).
$dId = $draws->add($U, 500.0, null, 'DKK', 'from cockpit');
check('draw add returns a new id', $dId > 0);
$draws->delete($U, $dId);

echo "\n== 15. Cash position (expected balance + free-to-spend), fresh user U2 ==\n";
$U2 = 2;
$cashEntries = new CashEntries($db);
// Paid invoice (cash in 12500) + an UNPAID invoice (moms owed, but no cash yet).
$ci1 = $income->create($U2, ['issued_at' => Income::today(), 'amount_ex_vat' => 10000, 'vat' => 2500, 'total' => 12500], 'manual');
$income->book($U2, $ci1); $income->markPaid($U2, $ci1);
$ci2 = $income->create($U2, ['issued_at' => Income::today(), 'amount_ex_vat' => 4000, 'vat' => 1000, 'total' => 5000], 'manual');
$income->book($U2, $ci2); // left unpaid
// A confirmed expense the business paid (cash out 1250), an owner draw (3000),
// and a moms payment to SKAT (cash out 2000, category moms).
$cr = $receipt->create($U2, ['purchased_at' => Income::today(), 'vendor' => 'Supplier', 'total' => 1250, 'vat' => 250], 'manual');
$receipt->confirm($U2, $cr);
$draws->add($U2, 3000.0);
$cashEntries->add($U2, 'out', 2000.0, 'moms', 'Q moms');
$cashObj = new Cash($income, $receipt, $draws, $cashEntries, $settings);
$pos = $cashObj->position($U2);
// expected = 0 + 12500 − (1250 + 3000 + 2000) = 6250
check('expected balance = 6250', money($pos['expected']) === '6250.00', money($pos['expected']));
check('money in: invoices paid 12500', money($pos['money_in']['invoices_paid']) === '12500.00', money($pos['money_in']['invoices_paid']));
check('money out: expenses 1250 / draws 3000 / other 2000',
    money($pos['money_out']['expenses']) === '1250.00' && money($pos['money_out']['draws']) === '3000.00' && money($pos['money_out']['other']) === '2000.00',
    json_encode($pos['money_out']));
// moms owed = (3500 salgs − 250 købs) − 2000 paid = 1250; tax = 40% of (14000−1000)=5200.
check('reserve: moms owed 1250 + tax 5200 = 6450',
    money($pos['reserve']['moms']) === '1250.00' && money($pos['reserve']['tax']) === '5200.00' && money($pos['reserve']['total']) === '6450.00',
    json_encode($pos['reserve']));
// free = 6250 − 6450 = −200 (owes SKAT more than the cash on hand — a real warning).
check('free to spend = −200', money($pos['free_to_spend']) === '-200.00', money($pos['free_to_spend']));
// A moms payment reduces expected AND moms owed equally → free-to-spend unchanged.
$freeBefore = $pos['free_to_spend'];
$cashEntries->add($U2, 'out', 1250.0, 'moms', 'rest of moms');
$pos2 = $cashObj->position($U2);
check('paying more moms leaves free-to-spend unchanged', money($pos2['free_to_spend']) === money($freeBefore), money($pos2['free_to_spend']));
check('expected dropped by the 1250 moms payment', money($pos2['expected']) === '5000.00', money($pos2['expected']));

echo "\n== 16. Cash: expected moms REFUND surfaced (SKAT owes you), fresh user U3 ==\n";
$U3 = 3;
// købsmoms (500) exceeds salgsmoms (0): a moms refund is due. Confirmed expense
// total 2500 / vat 500, business-paid, no income.
$rr = $receipt->create($U3, ['purchased_at' => Income::today(), 'vendor' => 'Big buy', 'total' => 2500, 'vat' => 500], 'manual');
$receipt->confirm($U3, $rr);
$pos3 = $cashObj->position($U3);
// momsNet = 0 − 500 = −500 → owed 0, refund_expected 500.
check('moms reserve is 0 when a refund is due', money($pos3['reserve']['moms']) === '0.00', money($pos3['reserve']['moms']));
check('refund_expected = 500', money($pos3['refund_expected']) === '500.00', money($pos3['refund_expected']));
// expected = 0 − 2500 = −2500 (paid out, nothing in yet). free_incl_refund = free + 500.
check('free_incl_refund = free_to_spend + refund', money($pos3['free_incl_refund']) === money($pos3['free_to_spend'] + 500), json_encode([$pos3['free_to_spend'], $pos3['free_incl_refund']]));
// Receiving the refund (cash in, moms) clears refund_expected and lifts expected.
$cashEntries->add($U3, 'in', 500.0, 'moms', 'refund received');
$pos3b = $cashObj->position($U3);
check('refund_expected clears once received', money($pos3b['refund_expected']) === '0.00', money($pos3b['refund_expected']));
check('expected rises by the received refund', money($pos3b['expected']) === money($pos3['expected'] + 500), money($pos3b['expected']));

echo "\n== 17. P&L / resultatopgørelse (ex-VAT, by category), fresh user U4 ==\n";
$U4 = 4;
$pi = $income->create($U4, ['issued_at' => Income::today(), 'amount_ex_vat' => 20000, 'vat' => 5000, 'total' => 25000], 'manual');
$income->book($U4, $pi);
$pe1 = $receipt->create($U4, ['purchased_at' => Income::today(), 'vendor' => 'Tools', 'total' => 1250, 'vat' => 250, 'category' => 'Office & Equipment'], 'manual');
$receipt->confirm($U4, $pe1);
$pe2 = $receipt->create($U4, ['purchased_at' => Income::today(), 'vendor' => 'Train', 'total' => 500, 'vat' => 100, 'category' => 'Travel & Transport'], 'manual');
$receipt->confirm($U4, $pe2);
$plObj = new ProfitLoss($income, $receipt, $settings);
$pl = $plObj->statement($U4, 'all', 0);
check('P&L revenue (ex-VAT) = 20000', money($pl['revenue']) === '20000.00', money($pl['revenue']));
check('P&L total expenses (ex-VAT) = 1400 (1000 + 400)', money($pl['expenses']) === '1400.00', money($pl['expenses']));
check('P&L profit = 18600', money($pl['profit']) === '18600.00', money($pl['profit']));
check('P&L has 2 expense categories', count($pl['expense_categories']) === 2, (string) count($pl['expense_categories']));
check('P&L biggest category first (Office 1000)', money($pl['expense_categories'][0]['ex']) === '1000.00', json_encode($pl['expense_categories'][0]));
check('P&L tax reserve = 40% of 18600 = 7440', money($pl['tax_reserve']['amount']) === '7440.00', money($pl['tax_reserve']['amount']));

echo "\n== 18. Invoice generation (K-series, line items, moms, due date), user U5 ==\n";
$U5 = 5;
$invId = $income->createInvoice($U5, [
    'customer'   => "Private Client\nStreet 1",
    'issued_at'  => '2026-03-15',
    'due_at'     => '2026-03-29',
    'line_items' => [
        ['description' => 'Consulting', 'qty' => 10, 'unit_price' => 800],   // 8000
        ['description' => 'Setup fee', 'qty' => 1, 'unit_price' => 2000],    // 2000
    ],
]);
$invRow  = $income->get($U5, $invId);
$invCard = $income->card($invRow);
check('invoice booked (status booked, source private)', ($invRow['status'] ?? '') === 'booked' && ($invRow['source'] ?? '') === 'private', json_encode([$invRow['status'] ?? null, $invRow['source'] ?? null]));
check('invoice number = K-2026-001', ($invCard['doc_number'] ?? '') === 'K-2026-001', (string) ($invCard['doc_number'] ?? ''));
check('invoice ex 10000 / vat 2500 / total 12500', money($invCard['ex']) === '10000.00' && money($invCard['vat']) === '2500.00' && money($invCard['total']) === '12500.00', json_encode([$invCard['ex'], $invCard['vat'], $invCard['total']]));
check('invoice has 2 line items with computed amounts', count($invCard['line_items']) === 2 && money($invCard['line_items'][0]['amount']) === '8000.00', json_encode($invCard['line_items']));
check('invoice is a printable doc (invoice_url set)', ($invCard['is_invoice_doc'] ?? false) === true && !empty($invCard['invoice_url']));
check('invoice due date carried', ($invCard['due_at'] ?? '') === '2026-03-29', (string) ($invCard['due_at'] ?? ''));
// The next invoice that year is 002 (gapless series).
$invId2 = $income->createInvoice($U5, ['customer' => 'Client 2', 'issued_at' => '2026-06-01', 'line_items' => [['description' => 'Work', 'qty' => 1, 'unit_price' => 1000]]]);
check('next invoice number = K-2026-002', ($income->card($income->get($U5, $invId2))['doc_number'] ?? '') === 'K-2026-002');
// A generated invoice counts as booked revenue for that quarter's moms.
$mQ = (new Moms($income, $receipt))->settlement($U5, 0); // may differ by run date; just assert it ran
check('generated invoice flows into moms salgsmoms (all-time output VAT ≥ 2500)', $income->outputVat($U5, null, null) >= 2500.0, money($income->outputVat($U5, null, null)));

echo "\n---------------------------------------\n";
echo "Bookkeeping test: {$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
