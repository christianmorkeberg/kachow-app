<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Memories;

/**
 * Tool: list everything the assistant knows about the user (their saved personal
 * facts), with ids. Thin wrapper over Data\Memories::all.
 */
final class GetAboutMe implements Tool
{
    public function __construct(private Memories $memories)
    {
    }

    public function name(): string
    {
        return 'get_about_me';
    }

    public function description(): string
    {
        return 'Lists everything you currently remember about the user — their saved personal '
            . 'facts, each with an id and category. Use when the user asks what you know about '
            . 'them, or before editing or removing a specific fact.';
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
        $facts = $this->memories->all($userId);

        return ['count' => count($facts), 'facts' => $facts];
    }
}
