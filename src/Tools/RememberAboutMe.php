<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Memories;

/**
 * Tool: save a lasting personal fact about the user. Thin wrapper over
 * Data\Memories::add. Meant to be called proactively as the user reveals things
 * about themselves.
 */
final class RememberAboutMe implements Tool
{
    public function __construct(private Memories $memories)
    {
    }

    public function name(): string
    {
        return 'remember_about_me';
    }

    public function description(): string
    {
        return 'Saves a lasting, useful personal fact about the user — who they are and how they '
            . 'live: work, family and people, health, routines, goals, important dates, and firm '
            . 'likes/dislikes. Call this PROACTIVELY whenever the user reveals such a fact; you do '
            . 'not need to be asked. Then briefly let them know you\'ll remember it. Do NOT save '
            . 'trivia, one-off or temporary details, or clearly sensitive information without a '
            . 'clear reason. Store one clear, self-contained fact per call, and do not re-save '
            . 'something already listed in what you know about the user.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'fact' => [
                    'type'        => 'string',
                    'description' => 'One self-contained fact, phrased about the user, '
                        . 'e.g. "Has a daughter named Ada, born 2021." or "Commutes ~40 min by bike."',
                ],
                'category' => [
                    'type'        => 'string',
                    'description' => 'Optional grouping, e.g. "work", "family", "health", '
                        . '"routine", "preferences", "dates". Defaults to "general".',
                ],
            ],
            'required' => ['fact'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $fact = trim((string) ($arguments['fact'] ?? ''));
        if ($fact === '') {
            return ['error' => 'A fact is required.'];
        }
        $category = trim((string) ($arguments['category'] ?? 'general'));

        $id = $this->memories->add($userId, $fact, $category);

        return ['remembered' => true, 'id' => $id];
    }
}
