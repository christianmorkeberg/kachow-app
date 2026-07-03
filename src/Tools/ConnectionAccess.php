<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Single, auditable gate for cross-user reads. Given the acting (viewing) user
 * and a named person, it confirms an ACCEPTED connection exists and that the
 * person shares the requested app — returning the owner's id only then.
 *
 * Keeping this in one place means the "controlled hole" in per-user scoping has
 * exactly one implementation to review.
 */
final class ConnectionAccess
{
    /**
     * @return array{owner_id: int, person: array<string, mixed>}|array{error: string}
     */
    public static function resolve(Connections $connections, int $viewerId, string $person, string $app): array
    {
        $person = trim($person);
        if ($person === '') {
            return ['error' => 'Specify who (email or name).'];
        }

        $entry = $connections->resolveByOther($viewerId, $person);
        if ($entry === null) {
            return ['error' => 'You are not connected with that person.'];
        }
        if ($entry['status'] !== 'accepted') {
            return ['error' => 'Your connection with them is not active yet.'];
        }

        $ownerId = (int) $entry['person']['id'];
        if (!in_array($app, $connections->sharedScopes($ownerId, $viewerId), true)) {
            return ['error' => 'They do not share their ' . $app . ' with you.'];
        }

        return ['owner_id' => $ownerId, 'person' => $entry['person']];
    }
}
