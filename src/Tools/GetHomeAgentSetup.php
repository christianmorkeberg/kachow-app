<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AgentCommands;
use App\Data\ApiTokens;

/**
 * Tool: the user's private home-agent (Raspberry Pi Pico W) connection details + whether the
 * Pico is currently online. Can rotate the token if it leaked.
 */
final class GetHomeAgentSetup implements Tool
{
    /** The Pico long-polls every ≤25 s; no contact for this long means it is offline. */
    public const ONLINE_WITHIN_SECONDS = 60;

    public function __construct(private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'get_home_agent_setup';
    }

    public function description(): string
    {
        return 'Gives the user the settings for their home agent (the Raspberry Pi Pico W on their home '
            . 'network that lets the assistant reach the Hue bridge and other local devices) — the server '
            . 'address and private token to put in the Pico\'s config — and says whether the Pico is online '
            . 'right now. Use when they ask to set up / check the Pico, home agent, or why home devices '
            . '(Hue lights) are unreachable. rotate=true issues a new token and invalidates the old one.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'rotate' => ['type' => 'boolean', 'description' => 'Issue a new token and invalidate the old one.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $rotate = !empty($arguments['rotate']);
        $token  = $rotate ? $this->tokens->rotate($userId, AgentCommands::SCOPE) : $this->tokens->ensure($userId, AgentCommands::SCOPE);
        $host   = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';

        $ago    = $this->tokens->secondsSinceUse($userId, AgentCommands::SCOPE);
        $online = $ago !== null && $ago <= self::ONLINE_WITHIN_SECONDS;

        return [
            'rotated'      => $rotate,
            'server_host'  => $host,
            'agent_token'  => $token,
            'online'       => $online,
            'last_contact' => $ago === null ? 'never' : $ago . ' seconds ago',
            'if_offline'   => 'Check the Pico has USB power and a green/steady Wi-Fi connection, the Wi-Fi name and '
                . 'password in its config.py are right (2.4 GHz only), and the token below matches. Unplugging and '
                . 'replugging it restarts it.',
            'note'         => 'Keep the token private — anyone with it can pose as the Pico. Ask me to rotate it if it leaks. '
                . 'Never paste it into a public repo.',
        ];
    }
}
