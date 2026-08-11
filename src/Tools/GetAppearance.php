<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserSettings;

/**
 * Tool: show the interactive appearance / theme picker card, so the user can browse
 * the looks and tap one. Distinct from update_setting (which sets a named theme
 * directly) — this just opens the picker without changing anything.
 */
final class GetAppearance implements Tool
{
    public function __construct(private UserSettings $settings)
    {
    }

    public function name(): string
    {
        return 'get_appearance';
    }

    public function description(): string
    {
        return 'Shows the interactive APPEARANCE / theme picker card so the user can browse the looks and '
            . 'TAP one to apply it. Use whenever the user wants to see, browse, pick or change the app\'s '
            . 'theme / look / appearance / colours (e.g. "I want to choose a theme", "let me pick a look", '
            . 'Danish "jeg vil vælge et tema", "skift udseende"). The card shows every theme and applies on '
            . 'tap, so DO NOT list the themes as text — just show the card with a one-line intro. If the user '
            . 'already named a specific theme to switch to (e.g. "make it lavender"), use update_setting '
            . 'instead.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object) [],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $theme = $this->settings->get($userId, 'theme') ?? 'aurora';

        return [
            'current_theme' => $theme,
            'themes'        => UserSettings::THEMES,
            'note'          => 'The app shows a tappable theme picker card — do NOT list the themes as text; '
                . 'just invite the user to tap one.',
            '_render'       => UserSettings::appearanceCard($theme),
        ];
    }
}
