<?php

declare(strict_types=1);

namespace App\Tools;

use App\Support\HueColor;

/**
 * Tool: turns a colour the user names ("red", "rød", "#ff8800", "warm white", "2700 K") into the JSON a Hue light takes, so the
 * assistant never guesses colour numbers. A lookup — it changes nothing; the assistant then PUTs the body with home_device_call.
 */
final class HueColorTool implements Tool
{
    public function name(): string
    {
        return 'hue_color';
    }

    public function description(): string
    {
        return 'Looks up the Hue colour for a colour the user asked for ("red", "blue", Danish "rød", "lilla", a hex like "#ff8800", "warm white", '
            . '"2700 K") and returns the exact JSON to put in a light\'s body. It changes nothing. For "make the kitchen light red": call this with '
            . '"red", then home_device_call PUT /clip/v2/resource/light/<id> (or grouped_light/<id> for a whole room) with the returned `body` merged with '
            . '{"on":{"on":true}} (and "dimming" if they gave a brightness). Never invent xy numbers yourself. Only colour-capable lamps take `color`; '
            . 'a white-only lamp takes `color_temperature`.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['color' => ['type' => 'string', 'description' => 'A colour word, hex code, white shade or kelvin value, e.g. "red", "#ff8800", "warm white", "2700K".']],
            'required'   => ['color'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $body = HueColor::body((string) ($arguments['color'] ?? ''));
        if ($body === null) {
            return ['error' => 'I do not know that colour. Use a basic colour word (red, orange, yellow, green, cyan, blue, purple, pink, magenta), a hex code like #ff8800, a white (warm white, cool white, daylight) or a kelvin value like 2700K.'];
        }

        return ['body' => $body, 'note' => 'Merge this into the light\'s PUT body (with {"on":{"on":true}} to switch it on).'];
    }
}
