<?php

declare(strict_types=1);

/**
 * Reading web pages safely: Support\WebSafety (the after-web tool gate, query hygiene, link detection),
 * Web\PageText, Support\TextDiff, Web\PageFetcher (SSRF guard, redirects, content types — fake DNS and
 * transport, no network) and the fetch_webpage tool with snapshots (in-memory SQLite):
 *   php -d extension=php_pdo_sqlite bin/web-fetch-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\WebSnapshots;
use App\Support\TextDiff;
use App\Support\WebSafety;
use App\Tools\FetchWebpage;
use App\Web\PageFetcher;
use App\Web\PageText;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/web-fetch-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) after reading the web, only read tools run that turn\n";
foreach (['get_emails', 'list_reminders', 'read_email', 'notify_me', 'web_search', 'fetch_webpage', 'get_calendar_events'] as $t) {
    check("allowed: {$t}", WebSafety::allowedAfterWeb($t));
}
foreach (['send_email', 'draft_email', 'home_device_call', 'delete_calendar_event', 'insert_calendar_event', 'add_to_shopping_list',
    'schedule_task', 'update_connection_sharing', 'create_invoice', 'remember_about_me', 'set_reminder', 'some_new_tool'] as $t) {
    check("blocked: {$t}", !WebSafety::allowedAfterWeb($t));
}
check('the block tells the model to hand it to the user', str_contains(WebSafety::blockedResult('send_email')['error'], 'next message'));

echo "\n2) search queries carry no personal identifiers\n";
check('a normal query is fine', WebSafety::queryProblem('Cafe X Copenhagen opening hours') === null);
check('a query with a year and a price is fine', WebSafety::queryProblem('iPhone 17 price 2026 under 8000 kr') === null);
foreach (['mail alex@example.com about it', 'who owns 12 34 56 78', '+45 12345678 owner', 'cpr 010190-1234', 'DK50 0040 0440 1162 43'] as $q) {
    check("refused: {$q}", WebSafety::queryProblem($q) !== null);
}
check('an overlong query is refused', WebSafety::queryProblem(str_repeat('word ', 50)) !== null);

echo "\n3) a pasted link counts as asking\n";
check('https link', WebSafety::hasUrl('what does https://example.com/item say?'));
check('www link', WebSafety::hasUrl('check www.example.dk please'));
check('no link', !WebSafety::hasUrl('what is the weather tomorrow'));
check('an email address is not a link', !WebSafety::hasUrl('write to alex@example.com'));

echo "\n4) page text\n";
$html = '<html><head><title>Shop &amp; Co</title><style>.x{}</style><script>var a=1</script></head><body>'
    . '<nav>Home | Cart</nav><h1>Blue kettle</h1><p>Price: 399&nbsp;kr</p><p>Sold&nbsp;out</p>'
    . '<table><tr><td>Size</td><td>1.5 L</td></tr></table><!-- secret --><script>track()</script></body></html>';
$p = PageText::extract($html);
check('title decoded', $p['title'] === 'Shop & Co', $p['title']);
check('one line per block, entities decoded', str_contains($p['text'], "Blue kettle\nPrice: 399 kr\nSold out"), $p['text']);
check('scripts, styles and comments gone', !str_contains($p['text'], 'track') && !str_contains($p['text'], '.x{}') && !str_contains($p['text'], 'secret'));
check('table cells kept on one line', str_contains($p['text'], 'Size · 1.5 L'), $p['text']);
check('a normal page does not need JavaScript', !$p['needs_js']);
$spa = '<html><body><div id="root"></div>' . str_repeat('<script src="x.js"></script>', 4) . '</body></html>';
check('an empty app shell is flagged as needing JavaScript', PageText::extract($spa)['needs_js']);
check('plain text passes through', PageText::extract("a\n\n  b  ", 'text/plain')['text'] === "a\nb");

echo "\n5) what changed\n";
$d = TextDiff::diff("Blue kettle\nPrice: 399 kr\nSold out", "Blue kettle\nPrice: 349 kr\nIn stock");
check('new and gone lines', $d['changed'] && $d['added'] === ['Price: 349 kr', 'In stock'] && $d['removed'] === ['Price: 399 kr', 'Sold out'], json_encode($d));
check('a block that only moved is not a change', !TextDiff::diff("a\nb\nc", "c\na\nb")['changed']);
check('no change', !TextDiff::diff("a\nb", "a\nb")['changed']);

echo "\n6) the fetcher refuses anything inside a private network\n";
$dns = static fn (array $map): callable => static fn (string $h): array => $map[$h] ?? [];
$calls = [];
$ok = static function (array $pages) use (&$calls): callable {
    return static function (string $url, string $ip, int $port) use ($pages, &$calls): array {
        $calls[] = [$url, $ip];
        return $pages[$url] ?? ['status' => 404, 'headers' => [], 'body' => '', 'error' => null];
    };
};
$f = new PageFetcher($dns(['shop.example' => ['93.184.216.34'], 'evil.example' => ['10.0.0.5'], 'mixed.example' => ['93.184.216.34', '127.0.0.1']]),
    $ok(['https://shop.example/a' => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8'], 'body' => '<p>hi</p>', 'error' => null]]));
check('a public site loads', $f->fetch('https://shop.example/a')['ok'] === true);
check('the connection is pinned to the checked IP', end($calls)[1] === '93.184.216.34');
foreach ([
    'http://127.0.0.1/' => 'loopback', 'http://192.168.68.107/clip/v2' => 'home LAN (the Hue bridge)', 'http://169.254.169.254/latest/meta-data' => 'cloud metadata',
    'http://[::1]/' => 'IPv6 loopback', 'https://evil.example/' => 'a name resolving to a private IP', 'https://mixed.example/' => 'a name with one private IP among public ones',
    'http://localhost/' => 'localhost', 'ftp://shop.example/' => 'non-http scheme', 'https://shop.example:8443/' => 'odd port',
    'https://user:pw@shop.example/' => 'credentials in the URL', 'https://nowhere.example/' => 'a name that does not resolve',
    'http://100.64.0.1/' => 'carrier-grade NAT', 'http://[::ffff:10.0.0.1]/' => 'IPv4-mapped private',
] as $u => $why) {
    check("refused: {$why}", $f->fetch($u)['ok'] === false);
}
$redir = new PageFetcher($dns(['shop.example' => ['93.184.216.34']]), $ok([
    'https://shop.example/go' => ['status' => 302, 'headers' => ['location' => 'http://192.168.1.1/admin'], 'body' => '', 'error' => null],
    'https://shop.example/r1' => ['status' => 301, 'headers' => ['location' => '/r2'], 'body' => '', 'error' => null],
    'https://shop.example/r2' => ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => '<p>landed</p>', 'error' => null],
    'https://shop.example/pdf' => ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF', 'error' => null],
]));
check('a redirect into the private network is refused', $redir->fetch('https://shop.example/go')['ok'] === false);
$r = $redir->fetch('https://shop.example/r1');
check('a normal relative redirect is followed', $r['ok'] && $r['final_url'] === 'https://shop.example/r2', json_encode($r));
check('a PDF is not read as a page', $redir->fetch('https://shop.example/pdf')['ok'] === false);
$loop = new PageFetcher($dns(['shop.example' => ['93.184.216.34']]), static fn (string $u): array =>
    ['status' => 302, 'headers' => ['location' => $u . 'x'], 'body' => '', 'error' => null]);
check('endless redirects stop', $loop->fetch('https://shop.example/')['ok'] === false);

echo "\n7) the tool, with snapshots\n";
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE web_snapshots (user_id INT, url_hash TEXT, url TEXT, content_hash TEXT, text TEXT, fetched_at TEXT, PRIMARY KEY (user_id, url_hash))');
$page = '<p>Blue kettle</p><p>Sold out</p>';
$fetcher = new PageFetcher($dns(['shop.example' => ['93.184.216.34']]), static function (string $u) use (&$page): array {
    return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => $page, 'error' => null];
});
$tool = new FetchWebpage($fetcher, new WebSnapshots($db));
$r1 = $tool->execute(['url' => 'shop.example/kettle'], 1);
check('a bare domain gets https', ($r1['url'] ?? '') === 'https://shop.example/kettle', json_encode($r1));
check('page text is labelled untrusted', str_contains((string) ($r1[WebSafety::UNTRUSTED_KEY] ?? ''), 'Sold out'));
check('the first read has nothing to compare', ($r1['since_last_check']['first_check'] ?? false) === true);
$page = '<p>Blue kettle</p><p>In stock</p>';
$r2 = $tool->execute(['url' => 'https://shop.example/kettle/'], 1);
check('the next read reports what changed (same page despite the trailing slash)', ($r2['since_last_check']['changed'] ?? null) === true
    && $r2['since_last_check']['added_lines'] === ['In stock'] && $r2['since_last_check']['removed_lines'] === ['Sold out'], json_encode($r2['since_last_check'] ?? null));
$r3 = $tool->execute(['url' => 'https://shop.example/kettle'], 1);
check('unchanged since then', ($r3['since_last_check']['changed'] ?? null) === false);
check('another user has their own history', ($tool->execute(['url' => 'https://shop.example/kettle'], 2)['since_last_check']['first_check'] ?? false) === true);
check('a private address is refused through the tool too', isset($tool->execute(['url' => 'http://192.168.68.107/'], 1)['error']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
