<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AgentCommands;
use App\Data\ApiTokens;
use App\Home\HomeOwner;
use App\Home\HueSync;
use App\Support\HomeResponse;
use InvalidArgumentException;

/**
 * Tool: make an HTTP call to a device on the user's home network, through the home agent
 * (Raspberry Pi Pico W). The device alias ("hue") and what paths it allows are configured on
 * the Pico itself — this only queues the request and waits for the answer.
 */
final class HomeDeviceCall implements Tool
{
    /** How long to wait for the Pico's answer. Long-polling means pickup is near-instant. */
    private const WAIT_SECONDS = 12.0;

    /** Budget for what we hand the model, applied AFTER noise is stripped (see HomeResponse). */
    private const MAX_RESPONSE_CHARS = 20000;

    public function __construct(
        private AgentCommands $commands,
        private ApiTokens $tokens,
        private ?HueSync $sync = null,
        private ?HomeOwner $owner = null,
    ) {
    }

    public function name(): string
    {
        return 'home_device_call';
    }

    public function description(): string
    {
        return 'Sends one HTTP request to a device on the user\'s HOME network through their home agent '
            . '(a Raspberry Pi Pico W) and returns the device\'s answer. device is the alias configured on the '
            . 'Pico: "hue" = the Philips Hue bridge (CLIP v2 API: GET /clip/v2/resource/light, '
            . '/clip/v2/resource/room, /clip/v2/resource/scene to look things up; PUT '
            . '/clip/v2/resource/light/<id> with {"on":{"on":true},"dimming":{"brightness":50}} to change one (a COLOUR: call hue_color for the exact body — never make up xy numbers); '
            . 'PUT /clip/v2/resource/grouped_light/<id> for a whole room; PUT /clip/v2/resource/scene/<id> with '
            . '{"recall":{"action":"active"}} to activate a scene); "pico" = the agent itself (GET /ping to '
            . 'test the connection). Look ids up with a GET first — never guess them — but ids you already looked '
            . 'up earlier in this conversation are listed in your context: reuse those and skip the lookup. The Pico only allows '
            . 'paths it was configured for, so a refused path is final. A timeout means the Pico is offline '
            . '(see get_home_agent_setup) or the device did not answer; the change may still have applied.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'device' => ['type' => 'string', 'description' => 'Device alias on the Pico, e.g. "hue" or "pico".'],
                'method' => ['type' => 'string', 'description' => 'GET, POST, PUT or DELETE.'],
                'path'   => ['type' => 'string', 'description' => 'Path on the device starting with "/", e.g. "/clip/v2/resource/light".'],
                'body'   => ['type' => 'object', 'description' => 'JSON body for POST/PUT. Omit for GET.'],
            ],
            'required' => ['device', 'method', 'path'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        // A person without a Pico of their own uses the one a connected person shares ("home" scope).
        $userId = $this->owner?->resolve($userId)['owner_id'] ?? $userId;
        $ago = $this->tokens->secondsSinceUse($userId, AgentCommands::SCOPE);
        if ($ago === null) {
            return ['error' => 'The home agent has never connected. Offer get_home_agent_setup to set up the Pico.'];
        }
        if ($ago > GetHomeAgentSetup::ONLINE_WITHIN_SECONDS) {
            return ['error' => 'The home agent (Pico) is offline — last contact ' . $ago . ' seconds ago. '
                . 'Use get_home_agent_setup for what to check.'];
        }

        try {
            $id = $this->commands->enqueue(
                $userId,
                (string) ($arguments['device'] ?? ''),
                (string) ($arguments['method'] ?? 'GET'),
                (string) ($arguments['path'] ?? ''),
                isset($arguments['body']) && is_array($arguments['body']) ? $arguments['body'] : null
            );
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        $result = $this->commands->awaitResult($userId, $id, self::WAIT_SECONDS);

        if ($result['state'] === 'timeout') {
            return ['error' => 'No answer from the home agent in ' . (int) self::WAIT_SECONDS . ' seconds. It may be '
                . 'offline or the device is not responding; the change may still have applied.'];
        }
        if ($result['state'] === 'failed') {
            return ['error' => 'The home agent could not complete that: ' . ($result['error'] ?? 'unknown error')];
        }

        $response = (string) ($result['response'] ?? '');
        $decoded  = json_decode($response, true);

        if (is_array($decoded)) {
            $this->remember($userId, $arguments, $result['http_status'], $decoded);

            return ['http_status' => $result['http_status']] + HomeResponse::compact($decoded, self::MAX_RESPONSE_CHARS);
        }

        // Not JSON (or cut short on the way back) — hand over plain text, flagged if it was cut.
        $cut = strlen($response) > self::MAX_RESPONSE_CHARS;

        return [
            'http_status' => $result['http_status'],
            'response'    => $cut ? mb_substr($response, 0, self::MAX_RESPONSE_CHARS) : $response,
        ] + ($cut ? ['truncated' => true] : []);
    }

    /** A list the assistant fetched anyway also refreshes the saved name -> id list, for free. */
    private function remember(int $userId, array $arguments, ?int $httpStatus, array $decoded): void
    {
        if ($this->sync === null || strtolower((string) ($arguments['device'] ?? '')) !== HueSync::DEVICE) {
            return;
        }
        try {
            $this->sync->ingest($userId, (string) ($arguments['method'] ?? ''), (string) ($arguments['path'] ?? ''), $httpStatus, $decoded);
        } catch (\Throwable $e) {
            // The saved list is a convenience — never let it break a light command.
        }
    }
}
