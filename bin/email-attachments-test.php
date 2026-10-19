<?php

declare(strict_types=1);

/**
 * Email attachments: the IMAP MIME walker (real attachments vs inline images), EmailMessage::toArray, and the
 * attach_email_file tool saving a PDF onto an expense. In-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/email-attachments-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\Income;
use App\Data\Receipts;
use App\Data\EmailAccounts;
use App\Email\EmailDraft;
use App\Email\EmailMessage;
use App\Email\EmailProvider;
use App\Email\EmailService;
use App\Email\ImapProvider;
use App\Receipts\ReceiptStorage;
use App\Tools\AttachEmailFile;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/email-attachments-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) the IMAP MIME walker\n";
$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$mime = "Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
    . "--outer\r\nContent-Type: multipart/alternative; boundary=\"alt\"\r\n\r\n"
    . "--alt\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nYour receipt\r\n"
    . "--alt\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>Hi <img src=\"cid:logo\"></p>\r\n--alt--\r\n"
    . "--outer\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-ID: <logo>\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgo=\r\n"
    . "--outer\r\nContent-Type: application/pdf; name=\"Invoice 42.pdf\"\r\nContent-Disposition: attachment; filename=\"Invoice 42.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
    . chunk_split(base64_encode($pdf)) . "\r\n--outer--\r\n";
$ref  = new ReflectionClass(ImapProvider::class);
$imap = $ref->newInstanceWithoutConstructor();
$split = $ref->getMethod('splitHeaders'); $split->setAccessible(true);
$leaves = $ref->getMethod('attachmentLeaves'); $leaves->setAccessible(true);
[$h, $b] = $split->invoke($imap, $mime);
$found = $leaves->invoke($imap, $h, $b);
check('only the PDF is an attachment; the inline logo is not', count($found) === 1 && $found[0]['filename'] === 'Invoice 42.pdf' && $found[0]['mime'] === 'application/pdf', json_encode(array_column($found, 'filename')));
$dec = $ref->getMethod('decodeBinary'); $dec->setAccessible(true);
check('the part id is its position and the bytes decode back', $found[0]['id'] === '3' && $dec->invoke($imap, $found[0]['body'], $found[0]['encoding']) === $pdf, $found[0]['id']);
check('size is a base64 estimate close to the real size', abs($found[0]['size'] - strlen($pdf)) <= 3);
$single = "Content-Type: text/plain\r\n\r\nno attachments here";
[$h2, $b2] = $split->invoke($imap, $single);
check('a plain message has none', $leaves->invoke($imap, $h2, $b2) === []);

echo "2) the message shape\n";
$m = new EmailMessage('1', 't', 'a', 'b', 'Receipt', 's', '2026-10-19', false, 'body', '', [['id' => '3', 'filename' => 'Invoice 42.pdf', 'mime' => 'application/pdf', 'size' => 10]]);
check('a full read lists the attachments; a summary does not', $m->toArray(true)['attachments'][0]['filename'] === 'Invoice 42.pdf' && !isset($m->toArray(false)['attachments']));
check('no attachments → no key', !isset((new EmailMessage('1', 't', 'a', 'b', 's', 's', 'd', false, 'x'))->toArray(true)['attachments']));

echo "3) attach_email_file\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE email_accounts (id INTEGER PRIMARY KEY, user_id INT, provider TEXT, email TEXT, display_name TEXT, credentials TEXT, status TEXT DEFAULT "active")');
$db->exec("INSERT INTO email_accounts (id, user_id, provider, email, display_name, status) VALUES (1, 1, 'gmail', 'me@gmail.test', 'me', 'active'), (2, 2, 'gmail', 'other@gmail.test', 'o', 'active')");
$db->exec("CREATE TABLE receipts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    source TEXT DEFAULT 'manual', status TEXT DEFAULT 'draft', file_ref TEXT, mime TEXT,
    vendor TEXT, purchased_at TEXT, total NUMERIC, vat NUMERIC, currency TEXT DEFAULT 'DKK',
    category TEXT, note TEXT, line_items TEXT, paid_privately INTEGER NOT NULL DEFAULT 0,
    reimbursed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$box = new class ($pdf) implements EmailProvider {
    public array $asked = [];
    public function __construct(private string $pdf) {}
    public function listRecent(int $limit = 15, ?string $query = null): array { return []; }
    public function get(string $messageId): ?EmailMessage
    {
        return $messageId === 'm1' ? new EmailMessage('m1', 't', 'shop', 'me', 'Receipt', 's', '2026-10-19', false, 'body', '', [['id' => '2', 'filename' => 'Invoice 42.pdf', 'mime' => 'application/pdf', 'size' => 99]]) : null;
    }
    public function attachment(string $messageId, string $attachmentId): ?array
    {
        $this->asked[] = [$messageId, $attachmentId];

        return $messageId === 'm1' && $attachmentId === '2' ? ['filename' => 'Invoice 42.pdf', 'mime' => 'application/pdf', 'data' => $this->pdf] : null;
    }
    public function markRead(string $messageId): void {}
    public function markAllRead(int $limit = 100): int { return 0; }
    public function createDraft(EmailDraft $draft): string { return 'd'; }
    public function send(EmailDraft $draft): string { return 's'; }
    public function deleteDraft(string $draftId): void {}
};
$service  = new EmailService(new EmailAccounts($db), '', '', '', '', static fn (array $meta): EmailProvider => $box);
$dir      = sys_get_temp_dir() . '/kachow-att-test-' . bin2hex(random_bytes(4));
$storage  = new ReceiptStorage($dir);
$receipts = new Receipts($db);
$tool     = new AttachEmailFile($service, $receipts, new Income($db), $storage);

$id = $receipts->create(1, ['total' => 99, 'vendor' => 'Shop', 'purchased_at' => '2026-10-19'], 'manual');
$r  = $tool->execute(['email_id' => 'm1', 'attachment_id' => '2', 'kind' => 'expense', 'entry_id' => $id], 1);
$row = $receipts->get(1, $id);
check('the PDF is stored and attached to the expense', ($r['attached'] ?? false) === true && $row['file_ref'] !== null && $row['mime'] === 'application/pdf'
    && is_file($dir . '/1/' . $row['file_ref']) && file_get_contents($dir . '/1/' . $row['file_ref']) === $pdf, json_encode($r));
check('the card shows the file', ($r['_render']['has_image'] ?? false) === true);
$first = $row['file_ref'];
$tool->execute(['email_id' => 'm1', 'attachment_id' => '2', 'kind' => 'expense', 'entry_id' => $id], 1);
$row2 = $receipts->get(1, $id);
check('attaching again replaces the file and removes the old one', $row2['file_ref'] !== $first && !is_file($dir . '/1/' . $first));
check('a missing attachment is a clear error', isset($tool->execute(['email_id' => 'm1', 'attachment_id' => '9', 'kind' => 'expense', 'entry_id' => $id], 1)['error']));
check('someone else\'s entry cannot be touched', isset($tool->execute(['email_id' => 'm1', 'attachment_id' => '2', 'kind' => 'expense', 'entry_id' => $id], 2)['error']));
check('bad arguments are refused', isset($tool->execute(['email_id' => 'm1', 'attachment_id' => '2', 'kind' => 'bogus', 'entry_id' => $id], 1)['error']));

foreach (glob($dir . '/*/*') ?: [] as $f) { @unlink($f); }
foreach (glob($dir . '/*') ?: [] as $d) { @rmdir($d); }
@rmdir($dir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
