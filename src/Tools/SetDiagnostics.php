<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AppFlags;
use App\Data\Users;

/**
 * Tool (admin/developer only): toggle expensive diagnostics on/off at runtime — namely
 * capturing the model's "thought" summaries into each turn's diagnostics. Wanted mainly
 * while bootstrapping the feedback flow, and easy to turn off again (no redeploy).
 */
final class SetDiagnostics implements Tool
{
    public function __construct(
        private Users $users,
        private AppFlags $flags,
    ) {
    }

    public function name(): string
    {
        return 'set_diagnostics';
    }

    public function description(): string
    {
        return 'Developer/admin only: turn the capture of the model\'s "thoughts"/reasoning into '
            . 'diagnostics on or off (it costs extra tokens). Use for "turn on/off thought logging", '
            . '"stop capturing thoughts", "enable diagnostics thoughts". Routing and tool-call '
            . 'diagnostics are always captured regardless.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'thoughts' => [
                    'type'        => 'boolean',
                    'description' => 'true to capture model thought summaries, false to stop.',
                ],
            ],
            'required' => ['thoughts'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'Diagnostics settings are only available to the developer/admin.'];
        }
        if (!array_key_exists('thoughts', $arguments)) {
            return ['error' => 'Say whether to turn thought capture on or off.'];
        }

        $on = filter_var($arguments['thoughts'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $on = $on ?? (bool) $arguments['thoughts'];
        $this->flags->set('diag_thoughts', $on);

        return [
            'diag_thoughts' => $on ? 'on' : 'off',
            'note'          => 'Routing + tool-call diagnostics are always on; this only controls thoughts.',
        ];
    }
}
