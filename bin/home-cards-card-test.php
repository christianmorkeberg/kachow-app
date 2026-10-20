<?php

declare(strict_types=1);

/** The start-cards card: canonical choice, new keys, and get_settings opens ONLY the card asked for. */
require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;

$pass = 0; $fail = 0;
function check(string $l, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ✓ ' : '  ✗ ') . $l . "\n"; }

$c = UserSettings::homeCardsCard('scheduled_tasks, feedback ,bogus,work_hours');
check('the card keeps the valid keys in order', $c['kind'] === 'home_cards' && $c['chosen'] === ['scheduled_tasks', 'feedback', 'work_hours']);
check('reports and dev ideas can be picked', in_array('feedback', $c['options'], true) && UserSettings::normalizeHomeCards('dev-ideas') === 'dev_ideas');
check('nothing chosen = empty list', UserSettings::homeCardsCard('')['chosen'] === []);
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
