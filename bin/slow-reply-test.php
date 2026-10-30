<?php

declare(strict_types=1);

/** The words and the "someone is watching" marker of a slow turn that carries on in the background (api/chat.php). Pure; no network. */
require __DIR__ . '/../vendor/autoload.php';

use App\Notify\NotificationTypes;
use App\Support\Detach;
use App\Support\SlowReply;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

echo "1) language\n";
foreach (['Sæt radiatorer på 15 grader' => true, 'tænd lyset i stuen' => true, 'Hvad er elprisen nu?' => true, 'Turn the lights on' => false, 'set the radiators to 15 degrees' => false, 'what can you do for me' => false, 'ok' => false] as $msg => $da) {
    check('"' . $msg . '" -> ' . ($da ? 'Danish' : 'English'), SlowReply::isDanish($msg) === $da);
}
check('the interim message is in the language of the request', str_contains(SlowReply::interim('Sæt radiatorer på 15 grader'), 'længere end normalt') && str_contains(SlowReply::interim('Set the radiators'), 'longer than usual'));
check('the failure message too', str_contains(SlowReply::failed('tænd lyset'), 'lykkedes') && str_contains(SlowReply::failed('turn on the light'), 'could not finish'));

echo "2) the push banner text\n";
check('markdown and the quick-reply marker are stripped', SlowReply::pushBody("**Alle tre** radiatorer er sat til 15°.\n\n[[suggest: Ja | Nej]]") === 'Alle tre radiatorer er sat til 15°.');
$long = SlowReply::pushBody(str_repeat('ord ', 100));
check('long text is cut with an ellipsis', mb_strlen($long) <= 140 && str_ends_with($long, '…'), (string) mb_strlen($long));

echo "3) the watch marker\n";
$u = 987654; $c = 123;
@unlink(sys_get_temp_dir() . '/kachow-watch-' . $u . '-' . $c);
check('nobody watching before any poll', Detach::watched($u, $c) === false);
Detach::touchWatch($u, $c);
check('a poll marks the chat as watched', Detach::watched($u, $c) === true);
check('...for a short while only', Detach::watched($u, $c, time() + Detach::WATCH_SECONDS + 5) === false);
check('another conversation is not watched', Detach::watched($u, $c + 1) === false);
@unlink(sys_get_temp_dir() . '/kachow-watch-' . $u . '-' . $c);

echo "4) the notification type\n";
check('chat_reply exists and is on by default', NotificationTypes::exists(NotificationTypes::CHAT_REPLY) && NotificationTypes::defaultEnabled(NotificationTypes::CHAT_REPLY));
check('Detach is unavailable in this CLI (so a turn stays attached, as before)', Detach::available() === false);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
