<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserInstructions;

/**
 * Tool: save a standing instruction/preference. Thin wrapper over
 * Data\UserInstructions::add.
 */
final class RememberInstruction implements Tool
{
    public function __construct(private UserInstructions $instructions)
    {
    }

    public function name(): string
    {
        return 'remember_instruction';
    }

    public function description(): string
    {
        return 'Saves a standing instruction or preference the user wants you to remember and apply '
            . 'in all future conversations. Use when the user says things like "remember that…", '
            . '"from now on…", "I usually mean…", or otherwise states a lasting preference about how '
            . 'you should behave or interpret their requests. Store one clear, self-contained '
            . 'instruction per call.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'instruction' => [
                    'type'        => 'string',
                    'description' => 'The preference to remember, phrased as a standalone instruction, '
                        . 'e.g. "When logging workouts, weights are in kg." or "Keep replies to one sentence."',
                ],
            ],
            'required' => ['instruction'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $instruction = trim((string) ($arguments['instruction'] ?? ''));
        if ($instruction === '') {
            return ['error' => 'An instruction is required.'];
        }

        $id = $this->instructions->add($userId, $instruction);

        return ['remembered' => true, 'id' => $id];
    }
}
