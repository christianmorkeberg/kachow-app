<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Tool: opens the Home card — every room with its lights (on / off, brightness, colour) and its radiator thermostat. The card loads
 * and refreshes itself, so the tool only asks for it to be shown.
 */
final class ShowHome implements Tool
{
    public function name(): string
    {
        return 'show_home';
    }

    public function description(): string
    {
        return 'Opens the Home card: all rooms with their lights (switch, brightness, colour) and thermostats (temperature, target, +/−), to look at and '
            . 'control by hand. Use for "show my home", "show the lights", "open the thermostats", Danish "vis mit hjem", "vis lysene". It loads its own '
            . 'live state — do not read the lights or thermostats first, and do not list them in text; just say it is open.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments, int $userId): array
    {
        return ['shown' => true, '_render' => ['kind' => 'home', 'title' => 'Home'], 'note' => 'The Home card is open and loads its own state. Say so in a few words.'];
    }
}
