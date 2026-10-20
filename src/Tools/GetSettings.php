<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;

/**
 * Tool: list the user's configurable settings and their current values.
 */
final class GetSettings implements Tool
{
    public function __construct(private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'get_settings';
    }

    public function description(): string
    {
        return 'Lists the user\'s configurable settings and current values (e.g. which Google calendar '
            . 'name is used for work-log tracking). Use for "what are my settings?", "which calendar do '
            . 'you use for work?", Danish "hvilke indstillinger har jeg?", "hvilken kalender bruger du '
            . 'til mit arbejde?". It is a LOOKUP: it only opens the settings card on screen when show_card is true — set that '
            . 'ONLY when the user asked to SEE or open their settings; when you are just reading a value to answer a question or before '
            . 'changing one, leave it out (the card would pop up for no reason).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'show_card' => ['type' => 'boolean', 'description' => 'True only when the user asked to see/open their settings: shows the settings card.'],
            ],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $out = ['settings' => $this->settings->all($userId)];
        // The settings card (the personality slider, tap to change the tone) opens only when asked for: a lookup made to answer
        // something else must not throw a card onto the screen (reports #45, #47, #49).
        if (filter_var($arguments['show_card'] ?? false, FILTER_VALIDATE_BOOL)) {
            $out['note']    = 'The app displays a personality slider card. Give a brief spoken summary of the settings — do not re-list every setting as text.';
            $out['_render'] = UserSettings::personalityCard($this->settings->get($userId, 'personality') ?? '2');
        }

        return $out;
    }
}
