<?php

declare(strict_types=1);

/** App\Support\LocalTime — the UTC → Copenhagen conversions the model must never do by hand (report #83). php bin/localtime-test.php */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\LocalTime;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

check('winter: 15:41 UTC is 16:41 local (the #83 case)', LocalTime::label('2026-11-17 15:41:00') === 'Tue 17 Nov 16:41', (string) LocalTime::label('2026-11-17 15:41:00'));
check('summer: 12:00 UTC is 14:00 local', LocalTime::label('2026-07-10 12:00:00', 'H:i') === '14:00');
check('late evening UTC crosses to the next local day', LocalTime::label('2026-11-17 23:30:00', 'Y-m-d H:i') === '2026-11-18 00:30');
check('null and empty stay null', LocalTime::label(null) === null && LocalTime::label('  ') === null);
check('local → UTC (winter −1h)', LocalTime::toUtc('2026-11-17 16:41') === '2026-11-17 15:41:00');
check('local → UTC (summer −2h)', LocalTime::toUtc('2026-07-10 14:00') === '2026-07-10 12:00:00');
check('a Z / offset the model added is ignored: the digits are local', LocalTime::toUtc('2026-11-17 16:41Z') === '2026-11-17 15:41:00' && LocalTime::toUtc('2026-11-17 16:41:00+02:00') === '2026-11-17 15:41:00');
check('nonsense is null', LocalTime::toUtc('not a time') === null);
check('today() is a local date', preg_match('/^\d{4}-\d{2}-\d{2}$/', LocalTime::today()) === 1 && LocalTime::today() === (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d'));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
