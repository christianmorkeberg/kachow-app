<?php

declare(strict_types=1);

/**
 * Session options: a long lifetime for cookie and server data, own save folder, secure flag. No network, no DB:
 *   php bin/session-options-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Auth\Session;

$pass = 0; $fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}\n"; }
}

$dir = sys_get_temp_dir() . '/kachow-sess-' . bin2hex(random_bytes(4));
$o   = Session::options($dir, true);
check('the cookie and the server data both last 30 days', $o['cookie_lifetime'] === 30 * 86400 && $o['gc_maxlifetime'] === 30 * 86400);
check('the app cleans the folder itself (1 in 100 requests)', $o['gc_probability'] === 1 && $o['gc_divisor'] === 100);
check('an own, private save folder is created and used', ($o['save_path'] ?? '') === $dir && is_dir($dir) && (fileperms($dir) & 0777) === 0700);
check('secure only over https; httponly + Lax always', $o['cookie_secure'] === true && Session::options($dir, false)['cookie_secure'] === false && $o['cookie_httponly'] === true && $o['cookie_samesite'] === 'Lax');
$o2 = Session::options('/proc/definitely/not/writable', false);
check('an unusable folder falls back to the host default (no save_path)', !isset($o2['save_path']));
@rmdir($dir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
