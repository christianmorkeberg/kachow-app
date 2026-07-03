<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\Conversations;
use App\Tools\ToolRegistry;
use Throwable;

/**
 * Orchestrates one user turn: send the message + tool declarations to Gemini,
 * execute any functionCalls via the ToolRegistry, feed the results back, and
 * return the final natural-language reply. Persists the exchange to
 * conversations/messages along the way.
 */
final class AssistantLoop
{
    /** Guard against a model that keeps calling tools without concluding. */
    private const MAX_TOOL_ROUNDS = 5;

    private const DEFAULT_SYSTEM_INSTRUCTION =
        'You are a concise, helpful personal assistant. Answer briefly and clearly. '
        . 'Use the available tools when the user asks about, or wants to record, their workouts, '
        . 'wishlist, or calendar. Do not invent data — call a tool to look things up. When a tool '
        . 'returns an error, explain it plainly to the user.';

    public function __construct(
        private GeminiClient $gemini,
        private ToolRegistry $tools,
        private Conversations $conversations,
        private string $systemInstruction = self::DEFAULT_SYSTEM_INSTRUCTION,
    ) {
    }

    /**
     * Handles one user message in an existing conversation and returns the
     * assistant's reply text. $userId scopes all tool execution.
     */
    public function handle(int $userId, int $conversationId, string $userMessage): string
    {
        $this->conversations->addMessage($conversationId, 'user', $userMessage);

        $contents     = $this->buildContents($conversationId);
        $declarations = $this->tools->declarations();
        $system       = $this->systemInstruction
            . "\n\nCurrent date/time (UTC): " . gmdate('Y-m-d H:i:s') . '.';

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->gemini->generate($contents, $declarations, $system);
            $calls    = GeminiClient::extractFunctionCalls($response);

            if ($calls === []) {
                $reply = GeminiClient::extractText($response);
                $this->conversations->addMessage($conversationId, 'assistant', $reply);
                return $reply;
            }

            // Echo the model's tool-call turn back verbatim (preserves fields like
            // thoughtSignature that thinking models require on functionCall parts).
            $contents[] = GeminiClient::firstCandidateContent($response);

            // Execute each call, persist it, and gather the responses to send back.
            $responseParts = [];
            foreach ($calls as $call) {
                try {
                    $result = $this->tools->dispatch($call['name'], $call['args'], $userId);
                } catch (Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }

                $this->conversations->addMessage(
                    $conversationId,
                    'tool',
                    (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $call['name']
                );

                $functionResponse = ['name' => $call['name'], 'response' => $result];
                if (isset($call['id'])) {
                    $functionResponse['id'] = $call['id'];
                }
                $responseParts[] = ['functionResponse' => $functionResponse];
            }

            // NOTE: function responses are sent under role "user" for the
            // generativelanguage REST API. Verify against the live API on first
            // real call; a single constant to change if it wants "function".
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        $fallback = "Sorry — I couldn't complete that in a reasonable number of steps.";
        $this->conversations->addMessage($conversationId, 'assistant', $fallback);

        return $fallback;
    }

    /**
     * Rebuilds Gemini "contents" from stored history.
     *
     * For v1 we replay only prior user/assistant *text* turns as context. Tool
     * exchanges are handled fresh within the current turn and their stored rows
     * are kept for the record, not replayed as functionCall/response pairs
     * (which would need the original args persisted too). Simple and correct for
     * a single-turn tool loop.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildContents(int $conversationId): array
    {
        $contents = [];
        foreach ($this->conversations->messages($conversationId) as $message) {
            $role    = (string) $message['role'];
            $content = (string) $message['content'];

            if ($role === 'user') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $content]]];
            } elseif ($role === 'assistant') {
                $contents[] = ['role' => 'model', 'parts' => [['text' => $content]]];
            }
        }

        return $contents;
    }
}
