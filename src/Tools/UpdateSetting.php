<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;

/**
 * Tool: change one of the user's settings (a controlled key/value store).
 */
final class UpdateSetting implements Tool
{
    public function __construct(private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'update_setting';
    }

    public function description(): string
    {
        return 'Changes one of the user\'s settings. Keys: "work_calendar" — the name of the Google '
            . 'calendar used for work-log tracking and the afternoon nudge (default "Arbejde"); '
            . '"personality" — how much character the assistant\'s replies carry, on a scale of 1–5 '
            . '(1 = off/neutral, 5 = maximum; use for "turn your personality up to 5", "be a hype gym bro" '
            . '(→ 5), "keep it neutral" (→ 1), Danish "skru personligheden op", "vær mere neutral"); '
            . '"theme" — the visual look, one of aurora / noir / paper / lavender / blush / disco (use for '
            . '"switch to the lavender theme", "make it dark", "go disco", Danish "skift til lavendel-temaet"), or '
            . '"auto" to FOLLOW THE DEVICE\'s light/dark setting ("match my phone", "dark at night, light in the day", '
            . 'Danish "følg min telefons mørk/lys-indstilling"); "theme_dark" (aurora / noir / disco) and "theme_light" '
            . '(paper / lavender / blush) choose which look auto uses at night / by day ("use noir at night"); '
            . '"cycle_show_fertile" — "on"/"off". '
            . '"work_clock" — "location" switches the work clock to the automatic location clock (hours written '
            . 'from stays at work-type places; Shortcut punches ignored from then on), "shortcut" switches back '
            . '(use for "switch my work clock to location", Danish "brug lokation til arbejdsuret"). After switching '
            . 'to location, tell them to turn off the Shortcut automations, that a day they fix by hand is left '
            . 'alone, and that a visit with a tracking gap is skipped and they get a push to fill it in. '
            . '"job_aliases" — expands a short work-log job label to a full name (e.g. the "Arbejde" '
            . 'calendar just says "DSB" but log entries/replies should say "Danske Statsbaner"); one '
            . 'SHORT=Full Name pair per line (\\n between lines). This value is REPLACED wholesale, not '
            . 'merged — if get_settings shows job_aliases already has lines, include them all unchanged '
            . 'and only add/replace the one pair the user is changing, so you never silently drop an '
            . 'existing alias (use for "DSB should be called Danske Statsbaner", Danish "DSB skal hedde '
            . 'Danske Statsbaner"). '
            . 'Use for "use my calendar called Vagter for work", Danish "brug min kalender Vagter til '
            . 'arbejde". Pass an empty value to reset a key to its default.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'key'   => ['type' => 'string', 'description' => 'Which setting to change.', 'enum' => UserSettings::keys()],
                'value' => ['type' => 'string', 'description' => 'The new value (empty resets to default).'],
            ],
            'required' => ['key', 'value'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        if (!UserSettings::exists($key)) {
            return ['error' => 'Unknown setting. Valid keys: ' . implode(', ', UserSettings::keys()) . '.'];
        }
        $value = (string) ($arguments['value'] ?? '');
        // Personality is a 1–5 dial; accept a number or a spoken word ("full", "neutral").
        if ($key === 'personality' && $value !== '') {
            $value = UserSettings::normalizePersonality($value);
        }
        if (in_array($key, ['theme', 'theme_dark', 'theme_light'], true) && $value !== '') {
            $value = UserSettings::normalizeThemeFor($key, $value);
        }
        if ($key === 'home_cards' && $value !== '') {
            $clean = UserSettings::normalizeHomeCards($value);
            if ($clean === '') {
                return ['error' => 'None of those are start cards. Allowed: ' . implode(', ', UserSettings::HOME_CARD_KEYS) . '.'];
            }
            $value = $clean;
        }
        if ($key === 'work_clock' && $value !== '') {
            $v = mb_strtolower(trim($value));
            if (in_array($v, ['location', 'gps', 'auto', 'automatic', 'on', 'lokation', 'automatisk'], true)) {
                $value = 'location';
            } elseif (in_array($v, ['shortcut', 'shortcuts', 'ios', 'off', 'manual', 'genvej'], true)) {
                $value = 'shortcut';
            } else {
                return ['error' => 'work_clock must be "location" or "shortcut".'];
            }
        }

        $this->settings->set($userId, $key, $value);

        $result = [
            'updated'  => true,
            'key'      => $key,
            'value'    => $this->settings->get($userId, $key),
            'settings' => $this->settings->all($userId),
        ];
        // Changing the personality or theme shows the matching card reflecting the change.
        if ($key === 'personality') {
            $result['_render'] = UserSettings::personalityCard((string) $result['value']);
        } elseif (in_array($key, ['theme', 'theme_dark', 'theme_light'], true)) {
            $result['_render'] = $this->settings->appearance($userId);
        } elseif ($key === 'home_cards') {
            $result['_render'] = UserSettings::homeCardsCard((string) $result['value']);
        }

        return $result;
    }
}
