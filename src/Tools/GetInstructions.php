<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserInstructions;

/**
 * Tool: list the user's standing instructions. Thin wrapper over
 * Data\UserInstructions::all.
 */
final class GetInstructions implements Tool
{
    public function __construct(private UserInstructions $instructions)
    {
    }

    public function name(): string
    {
        return 'get_instructions';
    }

    public function description(): string
    {
        return 'Lists the standing instructions/preferences the user has asked you to remember, with '
            . 'their ids. Use when the user asks what you remember about them or what preferences are '
            . 'set. (You already receive these each turn; call this only when the user explicitly asks '
            . 'to see them or you need an id to forget one.)';
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
        $items = $this->instructions->all($userId);

        return [
            'count'        => count($items),
            'instructions' => $items,
        ];
    }
}
