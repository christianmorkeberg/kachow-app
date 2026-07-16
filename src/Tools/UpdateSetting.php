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
        return 'Changes one of the user\'s settings. Supported key: "work_calendar" — the name of the '
            . 'Google calendar used for work-log tracking and the afternoon nudge (default "Arbejde"). '
            . 'Use for "use my calendar called Vagter for work", "track work from the Shifts calendar", '
            . 'Danish "brug min kalender Vagter til arbejde". Pass an empty value to reset to the default.';
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

        $this->settings->set($userId, $key, $value);

        return [
            'updated'  => true,
            'key'      => $key,
            'value'    => $this->settings->get($userId, $key),
            'settings' => $this->settings->all($userId),
        ];
    }
}
