<?php

declare(strict_types=1);

namespace App\Voice;

use App\Data\VoiceLog;

/**
 * The spoken "are you sure?" for destructive voice tools, enforced on the server so the model cannot skip it.
 *
 * The first call (name + exact arguments) is NOT executed: it is logged as asked and the model is told to ask the
 * user. The same call is only executed when it comes back in a LATER conversational turn (the user has had a
 * chance to answer: the browser counts turns) within CONFIRM_SECONDS, and each confirmation is used once.
 */
final class ConfirmGate
{
    public const CONFIRM_SECONDS = 300;

    public function __construct(private VoiceLog $log)
    {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null null = go ahead and run it; otherwise the result to hand the model instead
     */
    public function check(int $userId, string $sessionId, string $name, array $args, int $turn, ?int $conversationId = null): ?array
    {
        $hash = self::hash($name, $args);
        $last = $this->log->latestConfirm($sessionId, $hash);

        if ($last !== null && $last['kind'] === 'confirm_ask'
            && $turn > $last['turn'] && (time() - $last['at']) <= self::CONFIRM_SECONDS) {
            $this->log->add($userId, $sessionId, 'confirm_done', $conversationId, $name, true, null, ['h' => $hash, 'turn' => $turn]);

            return null;
        }
        if ($last === null || $last['kind'] !== 'confirm_ask' || $turn > $last['turn'] || (time() - $last['at']) > self::CONFIRM_SECONDS) {
            $this->log->add($userId, $sessionId, 'confirm_ask', $conversationId, $name, null, null, ['h' => $hash, 'turn' => $turn]);
        }

        return [
            'needs_confirmation' => true,
            'done'               => false,
            'instruction'        => 'NOT done yet. Ask the user one short question to confirm, naming exactly what would be removed '
                . '(e.g. the event title and time). Only if they clearly say yes, call ' . $name . ' again with exactly the same arguments. '
                . 'If they say no or change the subject, drop it.',
        ];
    }

    /** @param array<string, mixed> $args */
    public static function hash(string $name, array $args): string
    {
        self::ksortDeep($args);

        return substr(sha1($name . '|' . json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /** @param array<mixed> $a */
    private static function ksortDeep(array &$a): void
    {
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                self::ksortDeep($v);
            }
        }
    }
}
