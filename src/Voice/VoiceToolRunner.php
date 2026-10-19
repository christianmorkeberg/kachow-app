<?php

declare(strict_types=1);

namespace App\Voice;

use App\Tools\ToolRegistry;
use Throwable;

/**
 * Runs ONE tool call from a voice session: allow-list check, dispatch (or the gateway to the full assistant),
 * timing, and shaping — the model gets a trimmed result, the screen gets the card (`_render`) separately.
 * An error is still handed to the model (so it can say in a sentence what went wrong) and flagged here.
 */
final class VoiceToolRunner
{
    private const MAX_LIST = 40;
    private const MAX_STRING = 600;

    /**
     * @param callable(string): array{reply:string, card:?array} $gateway runs a request through the full assistant
     */
    public function __construct(private ToolRegistry $registry, private $gateway, private ?ConfirmGate $gate = null)
    {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{ok:bool, result:array<string, mixed>, card:?array<string, mixed>, ms:int, error:?string, fault:bool} fault = the tool THREW (a bug or an outage),
     *         as opposed to a tool answering with an error the model can act on ("no time given") — only faults are worth waking the developer for
     */
    public function run(string $name, array $args, int $userId, string $sessionId = '', int $turn = 0, ?int $conversationId = null): array
    {
        $t0 = microtime(true);
        $ms = static fn (): int => (int) round((microtime(true) - $t0) * 1000);

        if (!VoiceTools::isAllowed($name)) {
            return ['ok' => false, 'result' => ['error' => 'That is not available by voice.'], 'card' => null, 'ms' => $ms(), 'error' => 'not allowed by voice: ' . $name, 'fault' => false];
        }
        if (VoiceTools::needsConfirm($name)) {
            if ($this->gate === null) {
                return ['ok' => false, 'result' => ['error' => 'That needs a spoken confirmation, which is not available here.'], 'card' => null, 'ms' => $ms(), 'error' => 'no confirm gate', 'fault' => false];
            }
            $held = $this->gate->check($userId, $sessionId, $name, $args, $turn, $conversationId);
            if ($held !== null) {
                return ['ok' => true, 'result' => $held, 'card' => null, 'ms' => $ms(), 'error' => null, 'fault' => false];
            }
        }
        try {
            if ($name === VoiceTools::GATEWAY) {
                $request = trim((string) ($args['request'] ?? ''));
                if ($request === '') {
                    return ['ok' => false, 'result' => ['error' => 'What should I ask?'], 'card' => null, 'ms' => $ms(), 'error' => 'empty request', 'fault' => false];
                }
                $g      = ($this->gateway)($request);
                $result = ['answer' => (string) $g['reply']];
                $card   = $g['card'] ?? null;
            } else {
                $result = $this->registry->dispatch($name, $args, $userId);
                $card   = isset($result['_render']) && is_array($result['_render']) ? $result['_render'] : null;
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'result' => ['error' => 'The tool failed.'], 'card' => null, 'ms' => $ms(), 'error' => get_class($e) . ': ' . $e->getMessage(), 'fault' => true];
        }

        $error = isset($result['error']) ? (is_string($result['error']) ? $result['error'] : json_encode($result['error'])) : null;

        return ['ok' => $error === null, 'result' => self::shape($result), 'card' => $card, 'ms' => $ms(), 'error' => $error, 'fault' => false];
    }

    /**
     * Drops the screen-only keys and trims long lists/strings so a result stays small for the model.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public static function shape(array $r, int $depth = 0): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            if (is_string($k) && str_starts_with($k, '_')) {
                continue;
            }
            if (is_array($v)) {
                if ($depth >= 4) {
                    continue;
                }
                if (array_is_list($v) && count($v) > self::MAX_LIST) {
                    $n = count($v);
                    $v = array_slice($v, 0, self::MAX_LIST);
                    $out[$k . '_note'] = 'showing ' . self::MAX_LIST . ' of ' . $n;
                }
                $v = self::shape($v, $depth + 1);
            } elseif (is_string($v) && mb_strlen($v) > self::MAX_STRING) {
                $v = mb_substr($v, 0, self::MAX_STRING) . '…';
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
