<?php

declare(strict_types=1);

/** When to tell the user the home agent (Pico) has gone quiet. Pure. */
require __DIR__ . '/../vendor/autoload.php';

use App\Notify\NotificationTypes;
use App\Support\AgentHealth;

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . ($ok || $d === '' ? '' : "  ($d)") . "\n"; }

$at = static fn (string $t): DateTimeImmutable => new DateTimeImmutable($t, new DateTimeZone('Europe/Copenhagen'));

check('never contacted (not set up) → silent', !AgentHealth::shouldAlert(null, $at('2026-10-23 14:00')));
check('a Wi-Fi blip or router reboot (5 min) → silent', !AgentHealth::shouldAlert(300, $at('2026-10-23 14:00')));
check('just under 20 minutes → silent', !AgentHealth::shouldAlert(1199, $at('2026-10-23 14:00')));
check('20 minutes of silence in the daytime → alert', AgentHealth::shouldAlert(1200, $at('2026-10-23 14:00')));
check('the real case: 11.5 hours silent, afternoon → alert', AgentHealth::shouldAlert((int) (11.5 * 3600), $at('2026-10-23 14:08')));
check('at night: not now (07:00 is the first slot)', !AgentHealth::shouldAlert(7200, $at('2026-10-23 03:00')) && !AgentHealth::shouldAlert(7200, $at('2026-10-23 22:30')) && AgentHealth::shouldAlert(7200, $at('2026-10-23 07:00')));
check('after two weeks the user knows → stop', !AgentHealth::shouldAlert(15 * 86400, $at('2026-10-23 14:00')));
check('the notification type exists, is on by default and is not admin-only', NotificationTypes::exists('home_agent_offline') && NotificationTypes::defaultEnabled('home_agent_offline') && !NotificationTypes::isAdminOnly('home_agent_offline'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
