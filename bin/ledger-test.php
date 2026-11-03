<?php

declare(strict_types=1);

/**
 * Ledger / saldobalance / balance-sheet / balance-items tests — pure logic over an in-memory SQLite DB:
 *   php -d extension=php_pdo_sqlite bin/ledger-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\BalanceItems;
use App\Data\BalanceSheet;
use App\Data\Ledger;
use App\Data\LoanReports;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/ledger-test.php\n");
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
$db->exec("CREATE TABLE income (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, status TEXT, doc_number TEXT, customer TEXT, issued_at TEXT, paid_at TEXT,
    amount_ex_vat REAL, vat REAL, total REAL, currency TEXT DEFAULT 'DKK', category TEXT, note TEXT, file_ref TEXT)");
$db->exec("CREATE TABLE receipts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, status TEXT, vendor TEXT, purchased_at TEXT, total REAL, vat REAL, currency TEXT DEFAULT 'DKK',
    category TEXT, note TEXT, file_ref TEXT, paid_privately INT DEFAULT 0, reimbursed_at TEXT)");
$db->exec("CREATE TABLE owner_draws (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, drawn_at TEXT, amount REAL, currency TEXT DEFAULT 'DKK', note TEXT)");
$db->exec("CREATE TABLE cash_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, occurred_at TEXT, direction TEXT, amount REAL, category TEXT, note TEXT)");
$db->exec("CREATE TABLE balance_items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, kind TEXT, label TEXT, amount REAL, as_of TEXT, note TEXT)");

// 2025: invoice 10000 ex + 2500 moms (paid), invoice 8000 ex + 2000 moms (unpaid); expense 1000 incl. 200 moms (business-paid, with bilag);
// udlæg 500 incl. 100 moms (private, not repaid); draw 3000; moms payment 1000 out. A DRAFT invoice and a foreign-currency one must not show up.
$db->exec("INSERT INTO income (user_id,status,doc_number,customer,issued_at,paid_at,amount_ex_vat,vat,total,category,file_ref) VALUES
    (1,'booked','K-2025-001','Kunde A','2025-02-10','2025-03-01',10000,2500,12500,NULL,'a.pdf'),
    (1,'booked','K-2025-002','Kunde B','2025-11-20',NULL,8000,2000,10000,NULL,NULL),
    (1,'draft','K-2025-003','Kladde','2025-11-21',NULL,100,25,125,NULL,NULL),
    (1,'booked','K-2025-004','Udland','2025-05-01',NULL,100,0,100,NULL,NULL),
    (2,'booked','X','Anden bruger','2025-02-10','2025-03-01',999,0,999,NULL,NULL)");
$db->exec("UPDATE income SET currency='EUR' WHERE doc_number='K-2025-004'");
$db->exec("INSERT INTO receipts (user_id,status,vendor,purchased_at,total,vat,category,file_ref,paid_privately,reimbursed_at) VALUES
    (1,'confirmed','Staples','2025-01-15',1000,200,'Office & Equipment','b.jpg',0,NULL),
    (1,'confirmed','Taxa','2025-06-02',500,100,'Travel & Transport',NULL,1,NULL),
    (1,'draft','Kladde-bon','2025-06-03',50,10,NULL,NULL,0,NULL)");
$db->exec("INSERT INTO owner_draws (user_id,drawn_at,amount,note) VALUES (1,'2025-04-01',3000,'Løn')");
$db->exec("INSERT INTO cash_entries (user_id,occurred_at,direction,amount,category,note) VALUES (1,'2025-05-10','out',1000,'moms','Q1 moms')");

$ledger = new Ledger($db);

echo "1) kassekladde: order, voucher numbers, signs, exclusions\n";
$rows = $ledger->rows(1, '2025-01-01', '2025-12-31');
check('7 booked entries (no draft, no foreign currency, no other user)', count($rows) === 6, (string) count($rows));
check('date order', array_column($rows, 'date') === ['2025-01-15', '2025-02-10', '2025-04-01', '2025-05-10', '2025-06-02', '2025-11-20'], json_encode(array_column($rows, 'date')));
check('voucher numbers run 2025-0001…', $rows[0]['voucher'] === '2025-0001' && $rows[5]['voucher'] === '2025-0006', json_encode(array_column($rows, 'voucher')));
check('income positive, expense negative', $rows[1]['total'] === 12500.0 && $rows[0]['total'] === -1000.0 && $rows[0]['vat'] === -200.0 && $rows[0]['ex'] === -800.0);
check('draw and bank movement are outgoings', $rows[2]['total'] === -3000.0 && $rows[3]['total'] === -1000.0);
check('bilag flag follows file_ref', $rows[1]['receipt'] === true && $rows[5]['receipt'] === false);
check('payment status in Danish', $rows[1]['status'] === 'Betalt 01-03-2025' && $rows[5]['status'] === 'Ikke betalt' && $rows[4]['status'] === 'Udlæg – skyldig til ejer', json_encode(array_column($rows, 'status')));

echo "2) a filtered period keeps the whole year's voucher numbers\n";
$q2 = $ledger->rows(1, '2025-06-01', '2025-12-31');
check('June onwards is two rows', count($q2) === 2);
check('…numbered as in the full year (5 and 6)', $q2[0]['voucher'] === '2025-0005' && $q2[1]['voucher'] === '2025-0006', json_encode(array_column($q2, 'voucher')));

echo "3) Danish export table\n";
$t = $ledger->table(1, '2025-01-01', '2025-12-31');
check('decimal comma, no thousands separator', $t[1]['i_alt'] === '12500,00' && $t[0]['i_alt'] === '-1000,00', json_encode($t[1]));
check('Danish date', $t[1]['dato'] === '10-02-2025');
check('Danish headings', array_keys($t[0]) === ['bilagsnr', 'dato', 'type', 'tekst', 'konto', 'ex_moms', 'moms', 'i_alt', 'bilag', 'status']);
check('text has number · customer', $t[1]['tekst'] === 'K-2025-001 · Kunde A', $t[1]['tekst']);

echo "4) saldobalance\n";
$tb = $ledger->trialBalance(1, '2025-01-01', '2025-12-31');
$bySection = array_count_values(array_column($tb, 'sektion'));
check('sections Indtægter, Udgifter, Privat, Bank', array_keys($bySection) === ['Indtægter', 'Udgifter', 'Privat', 'Bank'], json_encode(array_keys($bySection)));
check('income account sums both invoices', $tb[0]['antal'] === '2' && $tb[0]['i_alt'] === '22500,00' && $tb[0]['moms'] === '4500,00', json_encode($tb[0]));

echo "5) balance items: latest in force, 0 retires\n";
$items = new BalanceItems($db);
$items->set(1, 'asset', 'Udstyr', 9000, '2025-01-01');
$items->set(1, 'asset', 'Udstyr', 7000, '2025-12-31');
$items->set(1, 'liability', 'Lån', 50000, '2025-06-01');
check('before the revaluation: 9000', array_column($items->inForce(1, '2025-06-30'), 'amount', 'label')['Udstyr'] === 9000.0);
check('after: 7000', array_column($items->inForce(1, '2025-12-31'), 'amount', 'label')['Udstyr'] === 7000.0);
check('loan not yet there in March', !isset(array_column($items->inForce(1, '2025-03-01'), 'amount', 'label')['Lån']));
$items->set(1, 'liability', 'Lån', 0, '2025-12-31');
check('amount 0 retires it', !isset(array_column($items->inForce(1, '2025-12-31'), 'amount', 'label')['Lån']));
$items->set(1, 'liability', 'Lån', 50000, '2025-12-31');

echo "6) balance sheet pr. 31-12-2025 (opening bank 2000)\n";
$sheet = new BalanceSheet($db);
$b = $sheet->asOf(1, '2025-12-31', 2000.0, $items->inForce(1, '2025-12-31'));
// bank = 2000 + 12500 received − 1000 expense − 3000 draw − 1000 moms payment = 9500 (udlæg is the owner's money: not business cash out)
// receivables 10000; moms: out 4500 − in 300 − paid 1000 = 3200 owed; udlæg 500; assets 9500+10000+7000=26500; debt 3200+500+50000=53700
$byPost = array_column($b['rows'], 'beloeb', 'post');
check('bank computed', $byPost['Bankindestående'] === '9500,00', json_encode($byPost));
check('receivables = unpaid invoice', $byPost['Tilgodehavender (udestående fakturaer)'] === '10000,00');
check('moms owed', $byPost['Skyldig moms'] === '3200,00');
check('udlæg owed to owner', $byPost['Udlæg skyldig til ejer'] === '500,00');
check('assets total', $b['assets'] === 26500.0, (string) $b['assets']);
check('equity is assets − debt (negative here)', $b['equity'] === -27200.0 && $b['liabilities'] === 53700.0, json_encode([$b['equity'], $b['liabilities']]));
check('passiver = aktiver', $byPost['Passiver i alt'] === $byPost['Aktiver i alt'], json_encode([$byPost['Passiver i alt'], $byPost['Aktiver i alt']]));

echo "7) as-of in the past + a stated bank balance\n";
$b2 = $sheet->asOf(1, '2025-02-28', 0.0, []);
$p2 = array_column($b2['rows'], 'beloeb', 'post');
check('end of Feb: invoice 1 issued and unpaid → receivable 12500', $p2['Tilgodehavender (udestående fakturaer)'] === '12500,00', json_encode($p2));
check('end of Feb: bank negative by the January expense', $p2['Bankindestående'] === '-1000,00');
$items->set(1, 'asset', 'Bank', 12345.67, '2025-12-31');
$b3 = $sheet->asOf(1, '2025-12-31', 2000.0, $items->inForce(1, '2025-12-31'));
$p3 = array_column($b3['rows'], 'beloeb', 'post');
check('stated bank balance overrides the computed one', $p3['Bankindestående'] === '12345,67' && str_starts_with($b3['bank_source'], 'oplyst saldo'), $b3['bank_source']);

echo "8) resultatopgørelse, fakturaoversigt, moms pr. kvartal, budget\n";
$lr = new LoanReports($db);
$pl = array_column($lr->profitLoss(1, '2025-01-01', '2025-12-31', 500.0), 'beloeb', 'post');
check('omsætning = 18000 ex moms', $pl['Omsætning'] === '18000,00', json_encode($pl));
check('udgifter by Danish category, ex moms (800 + 400) + kørsel 500', $pl['Kontor og udstyr'] === '-800,00' && $pl['Rejser og transport'] === '-400,00' && $pl['Udgifter i alt'] === '-1700,00', json_encode($pl));
check('resultat 16300', $pl['Resultat før skat'] === '16300,00');
$inv = $lr->invoices(1, '2025-01-01', '2025-12-31');
check('2 booked DKK invoices, paid + udestående', count($inv) === 2 && $inv[0]['status'] === 'Betalt 01-03-2025' && $inv[1]['status'] === 'Udestående' && $inv[1]['dage_siden_faktura'] !== '', json_encode($inv));
$cu = $lr->customers(1, '2025-01-01', '2025-12-31');
check('per customer: Kunde A 12500 paid, Kunde B 10000 outstanding', $cu[0]['kunde'] === 'Kunde A' && $cu[0]['udestaaende'] === '0,00' && $cu[1]['udestaaende'] === '10000,00', json_encode($cu));
$mq = $lr->momsQuarters(1, 2025);
check('Q1: salgsmoms 2500, købsmoms 200', $mq[0]['salgsmoms'] === '2500,00' && $mq[0]['koebsmoms'] === '200,00' && $mq[0]['til_betaling'] === '2300,00', json_encode($mq[0]));
check('Q2: købsmoms 100 (udlæg) and 1000 paid to SKAT', $mq[1]['koebsmoms'] === '100,00' && $mq[1]['betalt_til_skat'] === '1000,00', json_encode($mq[1]));
check('Q4: salgsmoms 2000; year total 4500', $mq[3]['salgsmoms'] === '2000,00' && $mq[4]['salgsmoms'] === '4500,00', json_encode($mq[4]));
$bu = $lr->budget(1, '2025-12-01', 10.0);
check('budget: 12 months + total, income avg 18000/12 +10%', count($bu['rows']) === 13 && $bu['avg_income'] === 1650.0 && $bu['rows'][0]['maaned'] === 'januar 2026', json_encode([$bu['avg_income'], $bu['rows'][0]]));
$bo = $lr->budget(1, '2025-12-01', 0.0, 20000.0, 5000.0);
check('budget overrides', $bo['rows'][12]['resultat'] === '180000,00', json_encode($bo['rows'][12]));

echo "9) the pack as one document\n";
$t1 = App\Support\DataExport::shape(App\Support\DataExport::table(['rows' => $mq]), [], 'da');
$page = App\Support\DataExport::html('Regnskabspakke', ['sections' => [['title' => 'A', 'table' => $t1], ['title' => 'B', 'table' => $t1]]], ['Periode' => '2025'], '', 'da');
check('two titled sections, second starts a new page', substr_count($page, '<h2') === 2 && substr_count($page, 'class="pb"') === 1);
check('Danish headings in the section', str_contains($page, 'Købsmoms') && str_contains($page, 'Betalt til SKAT'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
