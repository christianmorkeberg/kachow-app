<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Users;
use App\Data\VoiceLog;

/**
 * Tool (developer only): how the last voice sessions went — length, tool calls, errors, interruptions, time to
 * first audio, how each ended — from the voice event log.
 */
final class GetVoiceLog implements Tool
{
    public function __construct(private VoiceLog $log, private Users $users)
    {
    }

    public function name(): string
    {
        return 'get_voice_log';
    }

    public function description(): string
    {
        return 'Developer only: how the recent voice (Gemini Live) sessions went — for each: when, how long, tool calls, errors, '
            . 'interruptions, cancelled calls, time to first audio, how it ended (idle / limit / manual), and the last notable tool '
            . 'calls with their latency and any error. Use for "how did the voice session go?", "show the voice log", "any voice errors?".';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => ['sessions' => ['type' => 'integer', 'description' => 'How many recent sessions (default 5, max 20).']],
            'required'   => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        if (!$this->users->isAdmin($userId)) {
            return ['error' => 'The voice log is only available to the developer.'];
        }
        $n = isset($arguments['sessions']) ? (int) $arguments['sessions'] : 5;
        $s = $this->log->recentSessions($userId, $n);

        return ['count' => count($s), 'used_today_seconds' => $this->log->usedSecondsToday($userId), 'sessions' => $s];
    }
}
