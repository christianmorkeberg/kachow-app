<?php

declare(strict_types=1);

/** Mail text that is not valid UTF-8 must never break a JSON response (report #60: "Could not open that email"). No DB, no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Email\EmailMessage;
use App\Email\ImapProvider;
use App\Email\Utf8;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) cleaning\n";
$latin = "Indk\xf8bsordre Leverand\xf8r \xe6\xf8\xe5";
check('Latin-1 / Windows-1252 bytes become real æøå', Utf8::clean($latin) === 'Indkøbsordre Leverandør æøå');
check('valid UTF-8 is left alone', Utf8::clean('Indkøbsordre – æøå €') === 'Indkøbsordre – æøå €' && Utf8::clean('') === '');
check('a smart quote in Windows-1252 (0x93 / 0x94) survives', Utf8::clean("\x93quoted\x94") === '“quoted”');
check('garbage that is neither is cleaned to valid UTF-8, never left invalid', mb_check_encoding(Utf8::clean("ok \xff\xfe\x81 end"), 'UTF-8'));
check('deep: strings inside nested cards', Utf8::deep(['a' => ['b' => "x\xe6"], 'n' => 3, 'f' => true]) === ['a' => ['b' => 'xæ'], 'n' => 3, 'f' => true]);

echo "2) the message and the response\n";
$m = new EmailMessage('33', '33', "DSB \xf8", 'b', "Indk\xf8bsordre", 'x', 'd', false, $latin . "\nAtt.: Susanne Birch");
check('before the fix this body made json_encode fail (an empty response)', json_encode(['body' => $latin]) === false);
$json = json_encode(['ok' => true, 'card' => ['kind' => 'email'] + $m->toArray(true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
check('the card JSON now encodes and keeps the text readable', is_string($json) && str_contains($json, 'Indkøbsordre') && str_contains($json, 'Susanne Birch') && str_contains($json, 'DSB ø'));
check('the summary form (list rows) is cleaned too', is_string(json_encode($m->toArray(false))));

echo "3) the IMAP body decoder\n";
$prov = (new ReflectionClass(ImapProvider::class))->newInstanceWithoutConstructor();
$dec  = new ReflectionMethod(ImapProvider::class, 'decodeBody');
$dec->setAccessible(true);
check('no charset declared, 8-bit Latin-1 body → valid UTF-8', $dec->invoke($prov, $latin, '', 'text/plain') === 'Indkøbsordre Leverandør æøå');
check('declared utf-8 but Latin-1 bytes inside (a common mislabel) → valid UTF-8', $dec->invoke($prov, $latin, '', 'text/plain; charset="utf-8"') === 'Indkøbsordre Leverandør æøå');
check('declared iso-8859-1 → converted as before', $dec->invoke($prov, $latin, '', 'text/plain; charset=iso-8859-1') === 'Indkøbsordre Leverandør æøå');
check('quoted-printable Latin-1 with the charset declared', $dec->invoke($prov, 'Indk=F8b', 'quoted-printable', 'text/plain; charset=iso-8859-1') === 'Indkøb');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
