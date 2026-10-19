<?php

declare(strict_types=1);

/**
 * Version: which commit a checkout is on, read from .git without a shell:
 *   php bin/version-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Version;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

function repo(string $name, array $files): string
{
    $dir = sys_get_temp_dir() . '/kachow-version-' . getmypid() . '-' . $name;
    foreach ($files as $path => $content) {
        @mkdir(dirname($dir . '/' . $path), 0777, true);
        file_put_contents($dir . '/' . $path, $content);
    }

    return $dir;
}

$sha1 = substr(str_repeat('a', 7) . '1234567890123456789012345678901234', 0, 40);
$sha2 = substr('c6e9109' . '0123456789012345678901234567890123', 0, 40);
$zero = str_repeat('0', 40);

echo "1) a normal checkout\n";
$a = repo('loose', [
    '.git/HEAD'         => "ref: refs/heads/master\n",
    '.git/refs/heads/master' => $sha1 . "\n",
    '.git/logs/HEAD'    => "{$zero} {$sha1} Deploy <d@example.com> 1792000000 +0200\tclone\n{$sha1} {$sha1} Deploy <d@example.com> 1792425000 +0200\treset: moving to origin/master\n",
]);
$v = Version::ofRepo($a);
check('full and short sha from the branch ref', $v['sha'] === $sha1 && $v['short'] === substr($sha1, 0, 7), json_encode($v));
check('the time is the last reflog entry (the deploy)', $v['at'] === 1792425000, json_encode($v));

echo "2) other layouts\n";
$b = repo('packed', ['.git/HEAD' => "ref: refs/heads/master\n", '.git/packed-refs' => "# pack-refs\n{$sha2} refs/heads/master\n{$sha1} refs/heads/other\n"]);
$v = Version::ofRepo($b);
check('a ref that only exists in packed-refs', $v['sha'] === $sha2, json_encode($v));
check('no reflog: falls back to a file time', is_int($v['at']));
$c = repo('detached', ['.git/HEAD' => $sha1 . "\n"]);
check('a detached HEAD', Version::ofRepo($c)['sha'] === $sha1);
$d = repo('worktree', ['real/HEAD' => "ref: refs/heads/x\n", 'real/refs/heads/x' => $sha2 . "\n", '.git' => "gitdir: real\n"]);
check('a .git file pointing at the real git dir', Version::ofRepo($d)['sha'] === $sha2);

echo "3) not a checkout\n";
$e = repo('none', ['readme.txt' => 'x']);
check('no .git → null', Version::ofRepo($e) === null);
$f = repo('broken', ['.git/HEAD' => "ref: refs/heads/gone\n"]);
check('a ref that does not resolve → null', Version::ofRepo($f) === null);

echo "4) the menu line\n";
$line = Version::summary($a, $b);
check('web + app short shas and the latest deploy time (Copenhagen)', preg_match('/^web aaaaaaa · app c6e9109 · deployed \d{1,2} \w{3} \d{2}:\d{2}$/', (string) $line) === 1, (string) $line);
check('one repo missing: just the other', Version::summary($a, $e) !== null && !str_contains((string) Version::summary($a, $e), 'app '));
check('neither: nothing to show', Version::summary($e, $f) === null);
check('1792425000 is 19 Oct 2026 in Copenhagen', str_contains((string) Version::summary($a, $e), 'deployed 19 Oct'), (string) Version::summary($a, $e));

foreach (['loose', 'packed', 'detached', 'worktree', 'none', 'broken'] as $n) {
    $p = sys_get_temp_dir() . '/kachow-version-' . getmypid() . '-' . $n;
    exec('rm -rf ' . escapeshellarg($p));
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
