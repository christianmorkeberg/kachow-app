<?php

declare(strict_types=1);

namespace App\Tools;

use App\Auth\KioskAccess;
use App\Data\ApiTokens;

/**
 * Tool: the link that sets up a wall display (the kiosk) — open it once on the device and it stays signed in to the kiosk only.
 * Rotating issues a new link and signs every kiosk device out.
 */
final class GetKioskSetup implements Tool
{
    public function __construct(private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'get_kiosk_setup';
    }

    public function description(): string
    {
        return 'Gives the user the link that sets up their KIOSK — the full-screen wall display (electricity price, lights, thermostats) '
            . 'on a tablet or old phone. Opening the link once on that device keeps it signed in to the kiosk only (not chat or email). '
            . 'Use when they ask how to set up / open / reconnect the kiosk or wall display. rotate=true issues a new link and signs out '
            . 'every kiosk device (use if the link leaked or a device is lost). Share the link only with the user, never anywhere public.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'rotate' => ['type' => 'boolean', 'description' => 'Issue a new link and sign out all kiosk devices.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $rotate = !empty($arguments['rotate']);
        $token  = $rotate ? $this->tokens->rotate($userId, KioskAccess::SCOPE) : $this->tokens->ensure($userId, KioskAccess::SCOPE);
        $host   = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $ago    = $this->tokens->secondsSinceUse($userId, KioskAccess::SCOPE);

        return [
            'rotated'    => $rotate,
            'kiosk_link' => 'https://' . $host . '/kiosk.php?t=' . $token,
            'last_used'  => $ago === null ? 'never' : $ago . ' seconds ago',
            'how_to'     => 'Open the link once on the display device (it remembers it and the address becomes plain /kiosk.php), then add it to the '
                . 'home screen or bookmark it. In the app it is also under the menu → Kiosk (for the logged-in user).',
            'note'       => 'Anyone with this link can switch the lights and set the thermostats. Keep it private; ask me to rotate it if it leaks. '
                . 'Never paste it into a public repo.',
        ];
    }
}
