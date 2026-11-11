<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\Connections;

/**
 * What the Settings card's Accounts and People panels show, as plain data: the connected accounts (calendar, mailboxes) and the people the user shares with — so the
 * card is one request. Pure given its inputs (no I/O here besides the Connections read).
 */
final class SettingsPanels
{
    /**
     * @param list<array{id:int, provider:string, email:string, display_name:?string, status:string}> $mailboxes
     * @return array{calendar: array{connected: bool}, email: list<array{id:int, provider:string, email:string}>}
     */
    public static function accounts(bool $calendarConnected, array $mailboxes): array
    {
        return [
            'calendar' => ['connected' => $calendarConnected],
            'email'    => array_map(static fn (array $m): array => ['id' => (int) $m['id'], 'provider' => (string) $m['provider'], 'email' => (string) $m['email']], $mailboxes),
        ];
    }

    /**
     * The people panel: every connection (pending ones first), what the user shares with each and what they share back, and the scopes that can be shared.
     *
     * @return array{connections: list<array<string, mixed>>, scopes: list<array{key:string, help:string}>, is_admin: bool}
     */
    public static function people(Connections $connections, int $userId, bool $isAdmin): array
    {
        $list = $connections->listForUser($userId);
        usort($list, static fn (array $a, array $b): int => [$a['status'] === 'pending' ? 0 : 1] <=> [$b['status'] === 'pending' ? 0 : 1]);

        return [
            'connections' => $list,
            'scopes'      => array_map(static fn (string $k): array => ['key' => $k, 'help' => Connections::SCOPE_HELP[$k]], Connections::APPS),
            'is_admin'    => $isAdmin,
        ];
    }
}
