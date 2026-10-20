<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ApiTokens;

/**
 * Tool: the web address a sensor reports to (a Pico, a Shelly, an iPhone shortcut — anything that can open a URL), so
 * sensor routines ("when the kitchen light level drops below 50 …") have something to react to.
 */
final class GetSensorSetup implements Tool
{
    public const SCOPE = 'sensor';

    public function __construct(private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'get_sensor_setup';
    }

    public function description(): string
    {
        return 'Gives the user the personal web address their sensors report to (light level, a door or window, a temperature — from a Pico, a Shelly, '
            . 'a phone shortcut, anything that can open a URL), with a short example. Use when they ask how to connect a sensor, or before creating a '
            . 'routine with a sensor trigger. rotate=true issues a new address and invalidates the old one (if it leaked).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['rotate' => ['type' => 'boolean', 'description' => 'True to issue a new address and invalidate the old one.']],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $token = !empty($arguments['rotate']) ? $this->tokens->rotate($userId, self::SCOPE) : $this->tokens->ensure($userId, self::SCOPE);
        $host  = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $url   = 'https://' . $host . '/api/sensor.php?t=' . $token;

        return [
            'rotated'  => !empty($arguments['rotate']),
            'url'      => $url . '&s=<sensor name>&v=<reading>',
            'example'  => $url . '&s=kitchen_light&v=42',
            'how_it_works' => [
                's is the name the user gives the sensor, v is the reading: a number (42) or a state (open / closed / on / off).',
                'Send a reading whenever it changes, or every minute or so — a routine starts when the reading CROSSES its value, not while it stays there, and the first reading is only a baseline.',
                'On a Pico W (MicroPython): urequests.get(url + "&s=kitchen_light&v=" + str(reading)). A Shelly can call the address from an action / webhook. An iPhone shortcut can use "Get contents of URL".',
            ],
            'note' => 'Keep the address private — anyone with it can report readings. Then create_routine with trigger_sensor / trigger_sensor_op / trigger_sensor_value.',
        ];
    }
}
