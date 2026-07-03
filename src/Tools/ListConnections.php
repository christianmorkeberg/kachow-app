<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Connections;

/**
 * Tool: list the user's connections and pending requests.
 */
final class ListConnections implements Tool
{
    public function __construct(private Connections $connections)
    {
    }

    public function name(): string
    {
        return 'list_connections';
    }

    public function description(): string
    {
        return 'Lists the people you are connected with and any pending requests (incoming ones you '
            . 'can accept, and outgoing ones you sent). Shows each person, the status, and what each '
            . 'side shares. Use to see connections or to find who has a pending request.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $connections = $this->connections->listForUser($userId);

        return [
            'count'       => count($connections),
            'connections' => $connections,
        ];
    }
}
