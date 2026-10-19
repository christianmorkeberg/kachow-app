<?php

declare(strict_types=1);

/**
 * Markdown → HTML unit tests (bare URLs become safe links):
 *   php bin/markdown-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Markdown;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$link = static fn (string $u, ?string $text = null): string =>
    '<a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . ($text ?? $u) . '</a>';

$h = Markdown::toHtml('Link: https://forms.example.com/Resp.aspx?id=Oei4_XD__io&x=1 ok');
check('a bare URL is linked, query kept and escaped', str_contains($h, $link('https://forms.example.com/Resp.aspx?id=Oei4_XD__io&amp;x=1')), $h);
check('underscores in the URL are not turned into italics', !str_contains($h, '<em>'), $h);

$h = Markdown::toHtml('See (https://example.com/a). Done.');
check('a URL in parentheses loses the closing bracket', str_contains($h, $link('https://example.com/a') . ').'), $h);

$h = Markdown::toHtml('Go to https://example.com/q.');
check('a trailing full stop stays outside the link', str_contains($h, $link('https://example.com/q') . '.'), $h);

$h = Markdown::toHtml('Visit https://example.com/wiki/Foo_(bar) now');
check('a balanced bracket in the URL is kept', str_contains($h, $link('https://example.com/wiki/Foo_(bar)')), $h);

$h = Markdown::toHtml('[the form](https://example.com/p) and https://example.com/q');
check('a markdown link is not linked twice', substr_count($h, '<a ') === 2 && str_contains($h, $link('https://example.com/p', 'the form')), $h);

$h = Markdown::toHtml('`https://a.example/x` is code');
check('a URL in backticks stays code, not a link', $h === '<p><code>https://a.example/x</code> is code</p>', $h);

$h = Markdown::toHtml('<script>alert(1)</script> https://example.com/"><img src=x>');
check('markup in text or URL is escaped', !str_contains($h, '<script') && !str_contains($h, '<img'), $h);

$h = Markdown::toHtml('javascript:alert(1) and ftp://example.com/x');
check('only http(s) is linked', !str_contains($h, '<a '), $h);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
