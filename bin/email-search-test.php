<?php

declare(strict_types=1);

/**
 * Searching mail across mailboxes (report #33: get_emails silently used only the FIRST mailbox, the assistant
 * claimed to have searched all three, and then invented a reason when asked why it missed the mail).
 * Fake mailboxes behind the real EmailService/tools, in-memory SQLite, no network:
 *   php -d extension=php_pdo_sqlite bin/email-search-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\EmailAccounts;
use App\Email\EmailDraft;
use App\Email\EmailMessage;
use App\Email\EmailProvider;
use App\Email\EmailService;
use App\Email\OutlookProvider;
use App\Tools\GetEmails;
use App\Tools\ReadEmail;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/email-search-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

/** A mailbox with canned messages; remembers what it was asked. */
final class FakeMailbox implements EmailProvider
{
    /** @var list<string|null> */
    public array $queries = [];
    /** @var list<string> */
    public array $gets = [];
    public bool $down = false;

    /** @param list<EmailMessage> $messages */
    public function __construct(private array $messages)
    {
    }

    public function listRecent(int $limit = 15, ?string $query = null): array
    {
        $this->queries[] = $query;
        if ($this->down) { throw new RuntimeException('boom'); }
        $q = $query !== null ? trim(str_ireplace('is:unread', '', $query)) : '';
        $out = array_values(array_filter($this->messages, static fn (EmailMessage $m): bool =>
            $q === '' || stripos($m->from . ' ' . $m->subject . ' ' . $m->snippet, $q) !== false));

        return array_slice($out, 0, $limit);
    }

    public function get(string $messageId): ?EmailMessage
    {
        $this->gets[] = $messageId;
        if ($this->down) { throw new RuntimeException('boom'); }
        foreach ($this->messages as $m) {
            if ($m->id === $messageId) { return $m; }
        }

        return null;
    }

    public function attachment(string $messageId, string $attachmentId): ?array { return null; }
    public function markRead(string $messageId): void {}
    public function markAllRead(int $limit = 100): int { return 0; }
    public function createDraft(EmailDraft $draft): string { return 'd'; }
    public function send(EmailDraft $draft): string { return 's'; }
    public function deleteDraft(string $draftId): void {}
}

function msg(string $id, string $from, string $subject, string $date, bool $unread = false): EmailMessage
{
    return new EmailMessage($id, 't' . $id, $from, 'x@example.test', $subject, 'snippet of ' . $subject, $date, $unread, 'body of ' . $subject);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE email_accounts (id INTEGER PRIMARY KEY, user_id INT, provider TEXT, email TEXT, display_name TEXT, credentials TEXT, status TEXT DEFAULT "active")');
$db->exec("INSERT INTO email_accounts (id, user_id, provider, email, display_name, status) VALUES
    (1, 1, 'gmail',   'me@gmail.test',   'me@gmail.test', 'active'),
    (2, 1, 'outlook', 'me@hotmail.test', 'Me Hotmail',    'active'),
    (3, 1, 'imap',    'me@work.test',    'me@work.test',  'active'),
    (4, 2, 'gmail',   'other@gmail.test', 'other',        'active')");

// The shape of report #33: the mail is in Hotmail only; Gmail (the first mailbox) and IMAP have nothing for it.
$boxes = [
    1 => new FakeMailbox([msg('g1', 'Bank <bank@example.test>', 'Statement', '2026-10-15T08:00:00Z', true)]),
    2 => new FakeMailbox([
        msg('AQMk-own', 'Me <me@hotmail.test>', 'Re: Order 1914', '2026-10-14T20:13:45Z'),
        msg('AQMk-jes', 'jesper@wineshop.test', 'Re: Order 1914', '2026-10-14T20:13:08Z'),
        msg('AQMk-old', 'Shop <shop@example.test>', 'Welcome', '2026-09-01T10:00:00Z', true),
    ]),
    3 => new FakeMailbox([msg('77', 'Boss <boss@work.test>', 'Meeting', '2026-10-16T09:00:00Z', true)]),
];
$service = new EmailService(new EmailAccounts($db), '', '', '', '', static fn (array $meta): EmailProvider => $boxes[$meta['id']]);
$get  = new GetEmails($service);
$read = new ReadEmail($service);

echo "1) no account named → every mailbox is searched, and the answer says so\n";
$r = $get->execute(['query' => 'order'], 1);
check('all three mailboxes were asked', array_map(static fn (array $s): string => $s['account'], $r['searched']) === ['me@gmail.test', 'me@hotmail.test', 'me@work.test'], json_encode($r['searched'] ?? null));
check('and how many each returned', array_column($r['searched'], 'found') === [0, 2, 0], json_encode(array_column($r['searched'], 'found')));
check('the mail in the SECOND mailbox is found', $r['count'] === 2 && $r['emails'][0]['subject'] === 'Re: Order 1914');
check('every mail says which mailbox it is in', $r['emails'][0]['account'] === 'me@hotmail.test' && $r['emails'][0]['account_id'] === 2);
check('newest first', $r['emails'][0]['id'] === 'AQMk-own' && $r['emails'][1]['id'] === 'AQMk-jes');
check('the model is told to claim only what was searched', str_contains((string) ($r['note'] ?? ''), 'searched'));
check('the card items carry their own mailbox (the card itself names none)', ($r['_render']['items'][0]['account_id'] ?? null) === 2
    && array_key_exists('account_id', $r['_render']) && $r['_render']['account_id'] === null);

echo "\n2) one mailbox named\n";
$r = $get->execute(['query' => 'order', 'account' => 'hotmail'], 1);
check('only that one is searched', count($r['searched']) === 1 && $r['searched'][0]['account'] === 'me@hotmail.test' && $r['count'] === 2);
check('the card knows the mailbox', ($r['_render']['account_id'] ?? null) === 2);
$r = $get->execute(['query' => 'order', 'account' => 'yahoo'], 1);
check('an unknown name searches everything and says so', count($r['searched']) === 3 && str_contains((string) $r['note'], 'No mailbox matched "yahoo"'));

echo "\n3) a mailbox that cannot be reached is reported, not hidden\n";
$boxes[3]->down = true;
$r = $get->execute(['query' => 'order'], 1);
check('the others still answer', $r['count'] === 2 && count($r['searched']) === 2);
check('the failure is listed', ($r['failed'][0]['account'] ?? '') === 'me@work.test' && str_contains((string) $r['note'], 'could not be reached'));
$boxes[1]->down = $boxes[2]->down = true;
check('if none can be reached it is an error, not an empty list', isset($get->execute(['query' => 'order'], 1)['error']));
$boxes[1]->down = $boxes[2]->down = $boxes[3]->down = false;

echo "\n4) the query and unread flag reach every mailbox\n";
foreach ($boxes as $b) { $b->queries = []; }
$get->execute(['query' => 'from:bank', 'unread_only' => true], 1);
check('same query to all three', $boxes[1]->queries === ['from:bank is:unread'] && $boxes[2]->queries === ['from:bank is:unread'] && $boxes[3]->queries === ['from:bank is:unread']);

echo "\n5) merged by date across mailboxes, cut to the limit\n";
$r = $get->execute(['limit' => 2], 1);
check('the two newest overall', array_column($r['emails'], 'id') === ['77', 'g1'], json_encode(array_column($r['emails'], 'id')));
check('another user\'s mailbox is never searched', !in_array('other@gmail.test', array_column($r['searched'], 'account'), true));

echo "\n6) read_email finds the mailbox when none is named\n";
foreach ($boxes as $b) { $b->gets = []; }
$o = $read->execute(['id' => 'AQMk-jes'], 1);
check('found in Hotmail, with the right mailbox on the card', ($o['email']['subject'] ?? '') === 'Re: Order 1914' && ($o['_render']['account_id'] ?? null) === 2);
check('the IMAP mailbox is not asked for a non-numeric id', $boxes[3]->gets === []);
$o = $read->execute(['id' => '77'], 1);
check('a numeric id is found in IMAP', ($o['email']['subject'] ?? '') === 'Meeting' && ($o['_render']['account_id'] ?? null) === 3);
check('an unknown id says so', isset($read->execute(['id' => 'nope'], 1)['error']));
foreach ($boxes as $b) { $b->gets = []; }
$read->execute(['id' => 'AQMk-jes', 'account' => 'me@hotmail.test'], 1);
check('naming the mailbox asks only that one', $boxes[1]->gets === [] && $boxes[2]->gets === ['AQMk-jes'] && $boxes[3]->gets === []);

echo "\n7) Outlook: unread is a real filter, not search text\n";
$req = OutlookProvider::listRequest(12, null);
check('plain list: newest inbox first', str_contains($req['path'], '/me/mailFolders/inbox/messages') && str_contains(rawurldecode($req['path']), 'receivedDateTime desc') && !$req['after_unread']);
$req = OutlookProvider::listRequest(12, 'bonbon');
check('search: $search with the phrase', str_contains(rawurldecode($req['path']), '$search="bonbon"') && !str_contains($req['path'], 'filter') && !$req['after_unread']);
$req = OutlookProvider::listRequest(12, 'is:unread');
$p   = rawurldecode($req['path']);
check('unread only: inbox with a filter on isRead, ordered by the filtered date', str_contains($p, 'isRead eq false') && str_contains($p, 'receivedDateTime ge') && str_contains($p, 'receivedDateTime desc') && !str_contains($p, 'search')
    && !str_contains($p, 'is:unread'), $p);
check('…with a client-side fallback if Graph refuses the filter', is_string($req['fallback']) && str_contains(rawurldecode($req['fallback']), 'receivedDateTime desc'));
$req = OutlookProvider::listRequest(12, 'from:bank IS:UNREAD');
$p   = rawurldecode($req['path']);
check('search + unread: "is:unread" removed from the text, page over-fetched and filtered after', str_contains($p, '$search="from:bank"') && !str_contains($p, 'is:unread')
    && $req['after_unread'] === true && str_contains($p, '$top=48'), $p);
check('"is:unread" inside another word is left alone', !OutlookProvider::listRequest(12, 'this:is:unreadable')['after_unread']);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
