<?php

declare(strict_types=1);

/**
 * The Settings card: every setting appears exactly once (none missing, none doubled), with the right kind of field, the user's values and defaults, and work_clock normalising.
 *   php bin/settings-card-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE user_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, setting_key TEXT, setting_value TEXT, UNIQUE (user_id, setting_key))');
$db->exec("INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (1, 'personality', '4'), (1, 'company_name', 'Example ApS'), (1, 'work_clock', 'location')");
$S    = new UserSettings($db);
$card = $S->settingsCard(1);

$items = [];
foreach ($card['groups'] as $g) { foreach ($g['items'] as $it) { $items[$it['key']] = ($items[$it['key']] ?? 0) + 1; } }

echo "1) completeness\n";
check('a settings card', $card['kind'] === 'settings');
$expected = array_values(array_diff(UserSettings::keys(), UserSettings::CARD_OWN));
check('every setting is in the card exactly once (except the ones with their own card)', count($items) === count($expected) && array_diff($expected, array_keys($items)) === [] && max($items) === 1, json_encode(array_diff($expected, array_keys($items))));
check('Appearance / start cards are not listed (they have their own cards)', !isset($items['theme']) && !isset($items['home_cards']));
check('every group key in CARD_GROUPS is a real setting (no typo hides a setting)', (function () {
    foreach (UserSettings::CARD_GROUPS as [, , , $keys]) { foreach ($keys as $k) { if (!UserSettings::exists($k)) { return false; } } }
    return true;
})());

echo "2) fields and values\n";
$by = [];
foreach ($card['groups'] as $g) { foreach ($g['items'] as $it) { $by[$it['key']] = $it; } }
check('personality is a dial with the saved level', $by['personality']['type'] === 'dial' && $by['personality']['value'] === '4' && $by['personality']['is_custom'] === true);
check('a text setting carries its value', $by['company_name']['value'] === 'Example ApS' && $by['company_name']['type'] === 'text');
check('a numeric setting is a number with its default', $by['tax_reserve_pct']['type'] === 'number' && $by['tax_reserve_pct']['value'] === '40' && $by['tax_reserve_pct']['is_custom'] === false);
check('a long text is a text area', $by['company_address']['type'] === 'textarea' && $by['voice_stop_words']['type'] === 'textarea');
check('work clock is a choice with both options, on the saved value', $by['work_clock']['type'] === 'choice' && count($by['work_clock']['choices']) === 2 && $by['work_clock']['value'] === 'location');
check('the fertile-window toggle is a choice', $by['cycle_show_fertile']['type'] === 'choice');
check('the first group is the Assistant (personality first)', $card['groups'][0]['id'] === 'assistant' && $card['groups'][0]['items'][0]['key'] === 'personality');

echo "3) work clock values\n";
check('location / automatic words → location', UserSettings::normalizeWorkClock('Location') === 'location' && UserSettings::normalizeWorkClock('automatisk') === 'location');
check('manual / chat / shortcut → shortcut (the stored value)', UserSettings::normalizeWorkClock('manual') === 'shortcut' && UserSettings::normalizeWorkClock('chat') === 'shortcut' && UserSettings::normalizeWorkClock('shortcut') === 'shortcut');
check('anything else is refused', UserSettings::normalizeWorkClock('sometimes') === null);

echo "4) the Accounts panel data\n";
$acc = \App\Support\SettingsPanels::accounts(true, [['id' => 3, 'provider' => 'gmail', 'email' => 'a@example.com', 'display_name' => 'A', 'status' => 'active']]);
check('calendar status and mailboxes, without extra fields', $acc === ['calendar' => ['connected' => true], 'email' => [['id' => 3, 'provider' => 'gmail', 'email' => 'a@example.com']]], json_encode($acc));
check('no mailbox is an empty list', \App\Support\SettingsPanels::accounts(false, [])['email'] === []);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
