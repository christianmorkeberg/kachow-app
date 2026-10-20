<?php

declare(strict_types=1);

/**
 * get_settings is a lookup: the personality card opens only on show_card (reports #45, #47, #49).
 *   php -d extension=php_pdo_sqlite bin/get-settings-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;
use App\Tools\GetSettings;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/get-settings-test.php\n");
    exit(2);
}
$pass = 0; $fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; } else { $fail++; echo "  ✗ {$label}\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT)');
$tool = new GetSettings(new UserSettings($db));

$plain = $tool->execute([], 1);
check('a plain lookup returns the settings and opens no card', isset($plain['settings']) && !isset($plain['_render']));
$shown = $tool->execute(['show_card' => true], 1);
check('show_card opens the settings card', ($shown['_render']['kind'] ?? '') === 'personality');
check('"false" as a string does not', !isset($tool->execute(['show_card' => 'false'], 1)['_render']));
check('the schema offers show_card', isset($tool->parameters()['properties']['show_card']));

check('home_cards: known keys only, in order, no duplicates, no cap',
    UserSettings::normalizeHomeCards('Work_Hours, bogus, location work-hours;books moms cash pl mileage') === 'work_hours,location,books,moms,cash,pl,mileage'
    && UserSettings::normalizeHomeCards('nope') === '' && UserSettings::normalizeHomeCards(null) === '');
$us = new UserSettings($db);
$upd = new \App\Tools\UpdateSetting($us);
check('update_setting refuses a home_cards list with nothing valid (before any write)', isset($upd->execute(['key' => 'home_cards', 'value' => 'junk'], 1)['error']));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
