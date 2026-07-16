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
            . 'til mit arbejde?".';
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
        return ['settings' => $this->settings->all($userId)];
    }
}
