<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Vinyls;

/**
 * Tool: remove a vinyl from the collection.
 */
final class RemoveVinyl implements Tool
{
    public function __construct(private Vinyls $vinyls)
    {
    }

    public function name(): string
    {
        return 'remove_vinyl';
    }

    public function description(): string
    {
        return 'Removes a vinyl from the collection (e.g. sold or added by mistake). First call '
            . 'get_vinyls to find its id, then pass that id.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Id of the vinyl to remove (from get_vinyls).'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $id = isset($arguments['id']) ? (int) $arguments['id'] : 0;
        if ($id <= 0) {
            return ['error' => 'A valid vinyl id is required (from get_vinyls).'];
        }

        $deleted = $this->vinyls->delete($userId, $id);

        return $deleted ? ['deleted' => true, 'id' => $id] : ['deleted' => false, 'error' => 'No matching vinyl found.'];
    }
}
