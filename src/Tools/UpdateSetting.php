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
            . '"switch to the lavender theme", "make it dark", "go disco", Danish "skift til lavendel-temaet"); '
            . '"cycle_show_fertile" — "on"/"off". '
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
        if ($key === 'theme' && $value !== '') {
            $value = UserSettings::normalizeTheme($value);
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
        } elseif ($key === 'theme') {
            $result['_render'] = UserSettings::appearanceCard((string) $result['value']);
        }

        return $result;
    }
}
