<?php

declare(strict_types=1);

/**
 * Auto dark/light: the "auto" theme value (follow the device) and the dark/light looks it uses — validation,
 * the appearance card, and the update_setting / get_appearance tools. In-memory SQLite (a tiny shim rewrites the
 * one MySQL upsert), no network:
 *   php -d extension=php_pdo_sqlite bin/theme-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Data\UserSettings;
use App\Tools\GetAppearance;
use App\Tools\UpdateSetting;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite not loaded — re-run with:\n  php -d extension=php_pdo_sqlite bin/theme-test.php\n");
    exit(2);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

/** SQLite stand-in for the one MySQL-only statement UserSettings::set() uses. */
final class ShimPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            'ON CONFLICT(user_id, setting_key) DO UPDATE SET setting_value = excluded.setting_value', $query);

        return parent::prepare($query, $options);
    }
}

$db = new ShimPdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE user_settings (user_id INT, setting_key TEXT, setting_value TEXT, updated_at TEXT, PRIMARY KEY (user_id, setting_key))');
$settings = new UserSettings($db);

echo "1) the theme value\n";
check('a look stays a look', UserSettings::normalizeTheme('Noir') === 'noir' && UserSettings::normalizeTheme('paper') === 'paper');
check('"auto" and its spoken forms mean follow the device', UserSettings::normalizeTheme('auto') === 'auto' && UserSettings::normalizeTheme('System') === 'auto'
    && UserSettings::normalizeTheme('automatisk') === 'auto' && UserSettings::normalizeTheme('device') === 'auto');
check('anything unknown is still the default look', UserSettings::normalizeTheme('hot-pink') === 'aurora' && UserSettings::normalizeTheme(null) === 'aurora');

echo "\n2) the dark and light looks\n";
check('theme_dark takes only dark looks', UserSettings::normalizeThemeFor('theme_dark', 'noir') === 'noir' && UserSettings::normalizeThemeFor('theme_dark', 'disco') === 'disco');
check('a light look can\'t be the dark one (falls back to aurora)', UserSettings::normalizeThemeFor('theme_dark', 'paper') === 'aurora' && UserSettings::normalizeThemeFor('theme_dark', 'auto') === 'aurora');
check('theme_light takes only light looks', UserSettings::normalizeThemeFor('theme_light', 'lavender') === 'lavender' && UserSettings::normalizeThemeFor('theme_light', 'blush') === 'blush');
check('a dark look can\'t be the light one (falls back to paper)', UserSettings::normalizeThemeFor('theme_light', 'noir') === 'paper' && UserSettings::normalizeThemeFor('theme_light', '') === 'paper');
check('every look is exactly one of dark / light', array_values(array_diff(UserSettings::THEMES, UserSettings::DARK_THEMES, UserSettings::LIGHT_THEMES)) === []
    && array_intersect(UserSettings::DARK_THEMES, UserSettings::LIGHT_THEMES) === []);
check('the three settings exist, with those defaults', UserSettings::exists('theme') && UserSettings::defaultFor('theme_dark') === 'aurora' && UserSettings::defaultFor('theme_light') === 'paper');

echo "\n3) the appearance card\n";
$c = $settings->appearance(1);
check('defaults: aurora, with aurora / paper for auto', $c['kind'] === 'appearance' && $c['theme'] === 'aurora' && $c['theme_dark'] === 'aurora' && $c['theme_light'] === 'paper');
$set = new UpdateSetting($settings);
$r = $set->execute(['key' => 'theme', 'value' => 'auto'], 1);
check('update_setting theme=auto is stored and shown on the card', $r['value'] === 'auto' && $r['_render']['theme'] === 'auto' && $r['_render']['theme_light'] === 'paper');
$r = $set->execute(['key' => 'theme_dark', 'value' => 'noir'], 1);
check('theme_dark=noir is kept; the card carries the pair', $r['value'] === 'noir' && $r['_render']['theme'] === 'auto' && $r['_render']['theme_dark'] === 'noir');
$r = $set->execute(['key' => 'theme_dark', 'value' => 'paper'], 1);
check('a light look for the dark slot is corrected, never stored', $r['value'] === 'aurora' && $settings->get(1, 'theme_dark') === 'aurora');
$r = $set->execute(['key' => 'theme_light', 'value' => 'blush'], 1);
check('theme_light=blush', $r['value'] === 'blush' && $r['_render']['theme_light'] === 'blush');
check('another user is untouched', $settings->appearance(2)['theme'] === 'aurora' && $settings->appearance(2)['theme_light'] === 'paper');

echo "\n4) the picker tool shows the pair too\n";
$g = (new GetAppearance($settings))->execute([], 1);
check('get_appearance → the same card, auto and the chosen looks', $g['_render']['theme'] === 'auto' && $g['_render']['theme_light'] === 'blush' && $g['current_theme'] === 'auto');
$set->execute(['key' => 'theme', 'value' => 'lavender'], 1);
check('switching back to a fixed look keeps the saved pair for later', $settings->appearance(1)['theme'] === 'lavender' && $settings->appearance(1)['theme_light'] === 'blush');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
