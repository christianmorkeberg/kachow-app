<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the current request comes from, for the few places that must behave differently when the user is talking to Kachow by voice (api/live-tool.php sets it):
 * e.g. a radiator batch that all confirmed is not announced again by push, because the user has just heard the answer spoken.
 */
final class CallOrigin
{
    private static ?string $origin = null;

    public static function set(?string $origin): void
    {
        self::$origin = $origin;
    }

    public static function isVoice(): bool
    {
        return self::$origin === 'voice';
    }
}
