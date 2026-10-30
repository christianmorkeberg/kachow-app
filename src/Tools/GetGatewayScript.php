<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ApiTokens;
use App\Home\GatewayScript;

/**
 * Tool: the script for the Shelly BLU gateway that tells the server the moment a radiator valve reports (so confirmations and readings arrive by themselves).
 * Contains the user's private token; rotate=true issues a new one (the old script then stops being accepted).
 */
final class GetGatewayScript implements Tool
{
    public function __construct(private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'get_gateway_script';
    }

    public function description(): string
    {
        return 'Gives the user the script to install on their Shelly BLU gateway (its web page → Scripts) so the gateway tells the server whenever a radiator valve reports — '
            . 'the radiator readings and "set to 16 — confirmed" notifications then arrive instantly. Use when they ask how to set up / install / reinstall the gateway script or '
            . 'the Shelly webhook / push for the radiators. Give the script in a code block and the steps; it contains a private token, so never repeat it elsewhere. '
            . 'rotate=true issues a new token (the old script stops working).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['rotate' => ['type' => 'boolean', 'description' => 'Issue a new token; the previously installed script stops being accepted.']],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $rotate = !empty($arguments['rotate']);
        $token  = $rotate ? $this->tokens->rotate($userId, GatewayScript::SCOPE) : $this->tokens->ensure($userId, GatewayScript::SCOPE);
        $host   = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $ago    = $this->tokens->secondsSinceUse($userId, GatewayScript::SCOPE);

        return [
            'rotated'   => $rotate,
            'script'    => GatewayScript::code('https://' . $host, $token),
            'last_call' => $ago === null ? 'never (the script is not installed or has not reported yet)' : $ago . ' seconds ago',
            'steps'     => 'Open the gateway\'s web page in a browser on the home network → Scripts → create a new script → paste the code → Save → Start (and switch on "Run on startup"). '
                . 'Within about 5 minutes the console should show the gateway working and the server should see the first valve report.',
            'note'      => 'The token inside only lets the holder post valve readings, but keep it private; ask me to rotate it if it leaks.',
        ];
    }
}
