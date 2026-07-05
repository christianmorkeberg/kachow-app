<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\Conversations;
use App\Data\Memories;
use App\Data\UserInstructions;
use App\Tools\ToolRegistry;
use App\Tools\ToolSelector;
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
        . 'calendar, or shared shopping lists. Do not invent data — call a tool to look things up. '
        . 'When a tool returns an error, explain it plainly to the user. '
        . 'Treat "shopping", "groceries", and "the list" as the SHARED shopping list (shared with '
        . 'their connection) — use the shopping-list tools. Only use the personal wishlist when the '
        . 'user explicitly says "wishlist" or "gift"; never use it for groceries or everyday shopping. '
        . 'Report only numbers and facts that appear in a tool result; never estimate, round, or '
        . 'fill in values from memory. If a tool returns nothing, say so instead of guessing. '
        . 'When a question is about another person you are connected with, use only that person\'s '
        . 'tool result (e.g. get_connected_workouts) and attribute each number to the correct '
        . 'person — never mix their data with your own. '
        . 'Get to know the user over time: when they share a lasting, useful fact about themselves '
        . '(their life, work, family, health, routines, goals, preferences, or important dates), '
        . 'save it with remember_about_me — proactively, without being asked — and then briefly '
        . 'tell them you\'ll remember it. Do not save trivia, temporary details, or clearly '
        . 'sensitive information without a clear reason, and do not re-save something you already '
        . 'know. Use what you know about the user to help them, but do not recite it back unprompted.';

    public function __construct(
        private GeminiClient $gemini,
        private ToolRegistry $tools,
        private Conversations $conversations,
        private ?UserInstructions $instructions = null,
        private ?Memories $memories = null,
        private string $systemInstruction = self::DEFAULT_SYSTEM_INSTRUCTION,
    ) {
    }

    /**
     * Handles one user message in an existing conversation and returns the
     * assistant's reply text. $userId scopes all tool execution.
     */
    /**
     * @param array{lat: float, lon: float}|null $location optional device location (browser geolocation)
     */
    public function handle(int $userId, int $conversationId, string $userMessage, ?array $location = null): string
    {
        $this->conversations->addMessage($conversationId, 'user', $userMessage);

        $contents     = $this->buildContents($conversationId);
        // Send only the tools relevant to this message (falls back to all if unsure).
        $declarations = ToolSelector::select($this->tools->declarations(), $userMessage);
        $system       = $this->buildSystemInstruction($userId, $userMessage, $location);

        // The model is chosen on the first call (round 0, before any tool calls or
        // thoughtSignatures exist — so switching is safe) and reused for the rest of
        // the turn to keep thinking-model signatures consistent.
        $models      = $this->gemini->models();
        $chosenModel = null;

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            try {
                if ($chosenModel === null) {
                    [$response, $chosenModel] = $this->generateWithFallback($models, $contents, $declarations, $system);
                } else {
                    $response = $this->gemini->generate($contents, $declarations, $system, $chosenModel);
                }
            } catch (RateLimitException $e) {
                $reply = "I'm being rate-limited by the model right now — please try again in a few seconds.";
                $this->conversations->addMessage($conversationId, 'assistant', $reply);
                return $reply;
            }

            $calls = GeminiClient::extractFunctionCalls($response);

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
     * Sends the first request of a turn, trying each model in the chain until one
     * answers. Returns [response, modelThatAnswered]. Only used for round 0 — before
     * any tool calls or thoughtSignatures exist — so switching models is safe here.
     *
     * @param list<string>                     $models
     * @param array<int, array<string, mixed>> $contents
     * @param array<int, array<string, mixed>> $declarations
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws RateLimitException when every model in the chain is rate-limited.
     */
    private function generateWithFallback(array $models, array $contents, array $declarations, ?string $system): array
    {
        $last = null;
        foreach ($models as $model) {
            try {
                return [$this->gemini->generate($contents, $declarations, $system, $model), $model];
            } catch (RateLimitException $e) {
                $last = $e;
            }
        }

        throw $last ?? new RateLimitException('No model available.');
    }

    /**
     * Builds the system instruction for a turn: base prompt + current UTC time +
     * the user's stored standing instructions (so they always apply).
     */
    /**
     * @param array{lat: float, lon: float}|null $location
     */
    private function buildSystemInstruction(int $userId, string $userMessage = '', ?array $location = null): string
    {
        $system = $this->systemInstruction
            . "\n\nCurrent date/time (UTC): " . gmdate('Y-m-d H:i:s') . '.';

        if ($location !== null && isset($location['lat'], $location['lon'])) {
            $system .= sprintf(
                "\n\nThe user's current device location is approximately latitude %.4f, longitude %.4f. "
                . 'Use it for location-based tools (e.g. weather) unless they name a different place.',
                (float) $location['lat'],
                (float) $location['lon']
            );
        }

        if ($this->instructions !== null) {
            $stored = $this->instructions->all($userId);
            if ($stored !== []) {
                $system .= "\n\nThe user has given you these standing instructions — follow them "
                    . "unless the current message overrides one:";
                foreach ($stored as $row) {
                    $system .= "\n- (#" . (int) $row['id'] . ') ' . (string) $row['instruction'];
                }
            }
        }

        if ($this->memories !== null) {
            // Once memory grows past the budget, inject only the most-recent + the
            // facts relevant to this message (keeps the prompt small).
            $facts = MemorySelector::select($this->memories->all($userId), $userMessage);
            if ($facts !== []) {
                $system .= "\n\nWhat you already know about the user (use it to help them; each has an "
                    . "id for editing/forgetting; don't recite these back unprompted):";
                foreach ($facts as $row) {
                    $category = (string) ($row['category'] ?? '');
                    $tag      = ($category !== '' && $category !== 'general') ? ' [' . $category . ']' : '';
                    $system .= "\n- (#" . (int) $row['id'] . ')' . $tag . ' ' . (string) $row['content'];
                }
            }
        }

        return $system;
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
