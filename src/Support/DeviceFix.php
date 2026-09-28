<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The browser's own position fix sent with the current chat message (if any), so tools can
 * use it as "here" when OwnTracks has nothing fresh. One request = one turn, so a static
 * holder is enough; AssistantLoop::handle sets it at the start of every turn.
 */
final class DeviceFix
{
    /** @var array{lat:float, lon:float, acc:?int}|null */
    private static ?array $fix = null;

    /** @param array{lat:float, lon:float, acc?:int|float|null}|null $location */
    public static function set(?array $location): void
    {
        if ($location === null || !isset($location['lat'], $location['lon'])) {
            self::$fix = null;
            return;
        }
        $acc       = isset($location['acc']) && is_numeric($location['acc']) ? (int) round((float) $location['acc']) : null;
        self::$fix = ['lat' => (float) $location['lat'], 'lon' => (float) $location['lon'], 'acc' => $acc];
    }

    /** @return array{lat:float, lon:float, acc:?int}|null */
    public static function get(): ?array
    {
        return self::$fix;
    }
}
