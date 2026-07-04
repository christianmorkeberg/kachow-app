<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;

/**
 * Tool: change the acting user's display name. Thin wrapper over
 * Data\Users::updateName. The name shows in the app top bar and is what
 * connected people see for this user.
 */
final class SetMyName implements Tool
{
    /** Guard against absurd values that would break the UI/display. */
    private const MAX_LENGTH = 60;

    public function __construct(private Users $users)
    {
    }

    public function name(): string
    {
        return 'set_my_name';
    }

    public function description(): string
    {
        return 'Sets or changes the display name of the current user (the person you are talking to). '
            . 'Use when they say things like "call me…", "change my name to…", or "my name is…". '
            . 'This is the name shown in the app and to people they are connected with. It only ever '
            . 'affects the current user, never anyone else.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'The new display name, e.g. "Chris". Keep it short (a first name '
                        . 'or nickname). Max 60 characters.',
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'A name is required.'];
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            return ['error' => 'That name is too long — keep it under ' . self::MAX_LENGTH . ' characters.'];
        }

        $this->users->updateName($userId, $name);

        return ['updated' => true, 'name' => $name];
    }
}
