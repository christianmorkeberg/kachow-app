<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\UserInstructions;

/**
 * Tool: delete a standing instruction by id. Thin wrapper over
 * Data\UserInstructions::delete.
 */
final class ForgetInstruction implements Tool
{
    public function __construct(private UserInstructions $instructions)
    {
    }

    public function name(): string
    {
        return 'forget_instruction';
    }

    public function description(): string
    {
        return 'Removes a standing instruction the user previously asked you to remember. Use when the '
            . 'user says to forget or stop applying a preference. The id comes from the instructions '
            . 'you are given each turn (or from get_instructions).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => [
                    'type'        => 'integer',
                    'description' => 'The id of the instruction to remove.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid instruction id is required.'];
        }

        $removed = $this->instructions->delete($userId, $id);

        return $removed
            ? ['forgotten' => true, 'id' => $id]
            : ['forgotten' => false, 'error' => 'No matching instruction found.'];
    }
}
