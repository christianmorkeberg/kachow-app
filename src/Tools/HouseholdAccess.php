<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Resolves which connection a shared shopping-list call operates on, and verifies
 * the acting user is a member of it. The shared-write analogue of ConnectionAccess
 * (which gates read-through): here the resource is jointly owned by the two people
 * on an accepted connection.
 *
 * - No person given + exactly one accepted connection → use it.
 * - A person given → that accepted connection (by name/email).
 * - No person + several connections → ask which (ambiguous).
 * - No accepted connection → error.
 */
final class HouseholdAccess
{
    /**
     * @return array{connection_id:int, partner:array<string,mixed>}|array{error:string}
     */
    public static function resolve(Connections $connections, int $userId, ?string $person = null): array
    {
        $accepted = array_values(array_filter(
            $connections->listForUser($userId),
            static fn (array $c): bool => ($c['status'] ?? '') === 'accepted'
        ));

        if ($accepted === []) {
            return ['error' => 'You need an accepted connection with someone before you can use a shared shopping list.'];
        }

        $person = trim((string) $person);
        if ($person !== '') {
            $entry = $connections->resolveByOther($userId, $person);
            if ($entry === null || ($entry['status'] ?? '') !== 'accepted') {
                return ['error' => "You don't have an accepted connection with \"{$person}\"."];
            }

            return ['connection_id' => (int) $entry['connection_id'], 'partner' => $entry['person']];
        }

        if (count($accepted) === 1) {
            return ['connection_id' => (int) $accepted[0]['connection_id'], 'partner' => $accepted[0]['person']];
        }

        $names = implode(', ', array_map(
            static fn (array $c): string => (string) ($c['person']['name'] ?? $c['person']['email'] ?? 'someone'),
            $accepted
        ));

        return ['error' => "You have shared lists with more than one person ({$names}). Say whose list you mean."];
    }
}
