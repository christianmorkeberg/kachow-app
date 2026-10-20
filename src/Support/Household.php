<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Connections;
use App\Data\LocationPoints;
use App\Data\Places;

/**
 * Who else is home, for a routine's "only if nobody else is home" condition. The household is the people connected with the
 * HOME scope in either direction (the Pico serves them too). For each, their own latest position is checked against their own
 * home place (HomeNow). Only the yes / no / don't-know answer is used; nothing about where they are is shown or stored.
 */
final class Household
{
    /**
     * @param list<?bool> $members for each household member: true at home, false not, null unknown (no fresh position / no home place)
     * @return ?bool true = someone is home, false = everyone known is away, null = nobody could be judged
     */
    public static function summarize(array $members): ?bool
    {
        $known = array_filter($members, static fn ($m): bool => $m !== null);
        if ($known === []) {
            return null;
        }

        return in_array(true, $known, true);
    }

    /** @return list<int> user ids of the other household members */
    public static function memberIds(Connections $connections, int $userId): array
    {
        $ids = [];
        foreach ($connections->listForUser($userId) as $c) {
            if ($c['status'] !== 'accepted') {
                continue;
            }
            if (in_array('home', $c['you_share'], true) || in_array('home', $c['they_share'], true)) {
                $ids[] = (int) $c['person']['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public static function othersHome(Connections $connections, LocationPoints $points, Places $places, int $userId): ?bool
    {
        $res = [];
        foreach (self::memberIds($connections, $userId) as $id) {
            $point = $points->latest($id);
            $homes = array_filter($places->list($id), static fn (array $p): bool => ($p['type'] ?? '') === 'home');
            $res[] = $point === null || $homes === [] ? null : HomeNow::atHome($point, array_values($homes), time());
        }

        return self::summarize($res);
    }
}
