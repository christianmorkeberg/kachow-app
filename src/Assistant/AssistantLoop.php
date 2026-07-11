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
        . 'Always reply in the SAME language as the user\'s latest message — Danish if they wrote '
        . 'Danish, English if they wrote English. Do not switch languages on your own, even if '
        . 'earlier messages were in another language. '
        . 'Use the available tools when the user asks about, or wants to record, their workouts, '
        . 'calendar, or shared shopping lists. Do not invent data — call a tool to look things up. '
        . 'CRITICAL: whenever the user asks you to save, note, remember, log, add, or update something '
        . '(in Danish OR English — e.g. "gem", "husk", "noter", "tilføj"), you MUST actually call the '
        . 'matching tool and only confirm once it succeeds. Never tell the user you saved/noted/added '
        . 'something without having called the tool — an acknowledgement without a tool call is a bug. '
        . 'When a tool returns an error, explain it plainly to the user. '
        . 'Treat "shopping", "groceries", and "the list" as the SHARED shopping list (shared with '
        . 'their connection) — use the shopping-list tools. Only use the personal wishlist when the '
        . 'user explicitly says "wishlist" or "gift"; never use it for groceries or everyday shopping. '
        . 'When the user is about to train, says they are training, or asks what they are doing today '
        . 'or this week, ALWAYS call get_workout_plan (or get_week_plan) that turn — do not answer '
        . 'from memory. The app then renders the plan as an interactive checklist with tickboxes '
        . 'automatically, so give only a brief one-line intro (e.g. "Here\'s today\'s plan:") and NEVER '
        . 'write out the exercises, checkboxes, or "[ ]" marks yourself. '
        . 'The same applies to ANY list — shopping, groceries, to-do, tasks, packing, or any named list: '
        . 'they are ALL managed with the shopping-list tools (a "to-do list" is just a named list), and '
        . 'the app always displays them as an interactive CHECKLIST with tickboxes. So whenever you show '
        . 'or change a list, call the list tool and give only a brief intro — NEVER write the list items '
        . 'yourself as bullets, numbers, dashes, or "[ ]": doing so shows plain text instead of the '
        . 'checklist. Every list the user keeps must appear as the tickable checklist card, not prose. '
        . 'Likewise, when you show the user their schedule with get_calendar_events, the app renders '
        . 'the events as a calendar agenda card, so give a brief summary or answer their specific '
        . 'question — do not re-list every event line by line as text. '
        . 'Weather works the same way: the app shows a visual weather card, so give a short, natural '
        . 'summary (or answer the specific question) rather than reciting every measurement. If a '
        . 'weather tool reports that DMI is busy even after retrying, tell the user plainly and offer '
        . 'to try again shortly. '
        . 'Work hours work the same way too: get_work_hours renders a card, so give a brief summary '
        . '(e.g. total and whether they are still clocked in) instead of listing every session. '
        . 'When the user describes a business expense they paid (an amount, usually with a vendor or '
        . 'what it was for), record it with add_expense — this is their business bookkeeping, separate '
        . 'from the shopping list. It shows an editable draft card the user confirms, so give a brief '
        . 'intro and do not re-type the fields as text. (Receipt photos are read automatically by the '
        . 'app.) When they ask what they have spent, call get_expenses — it renders a totals card, so '
        . 'give the headline total rather than listing every receipt. export_expenses_csv returns a '
        . 'download_url — present it to the user as a clickable link to download the CSV. '
        . 'For email, use get_emails to check their mailbox (it renders a list card, so summarise '
        . 'rather than re-listing every message), read_email to open one, and draft_email or send_email '
        . 'to compose. You NEVER send email yourself: both tools prepare the message and show it with a '
        . 'Send button the user taps to confirm (or, if sending is turned off, it is saved to their '
        . 'Drafts). So when they ask you to send something, write it and tell them to review and tap '
        . 'Send. The user has more than one mailbox (Gmail, Hotmail, a work address): when they say '
        . 'which address to send FROM (or which mailbox to use), you MUST pass it as the account '
        . 'argument to draft_email/send_email, or it will go from the wrong mailbox. '
        . 'Only offer email actions if they have a mailbox connected; if not, tell them they '
        . 'can connect one with the "Connect email" button. '
        . 'Report only numbers and facts that appear in a tool result; never estimate, round, or '
        . 'fill in values from memory. If a tool returns nothing, say so instead of guessing. '
        . 'When a question is about another person you are connected with, use only that person\'s '
        . 'tool result (e.g. get_connected_workouts) and attribute each number to the correct '
        . 'person — never mix their data with your own. '
        . 'When the user proposes an idea for developing or improving the app itself — a feature to '
        . 'build later or a change to how Kachow works, often phrased "for later", "for the backlog", '
        . 'or "idea for the app" — save it with note_dev_idea and confirm briefly. This dev backlog is '
        . 'separate from their personal gift wishlist and their shopping list; do not confuse them. '
        . 'Get to know the user over time: when they share a lasting, useful fact about themselves '
        . '(their life, work, family, health, routines, goals, preferences, or important dates), '
        . 'save it with remember_about_me — proactively, without being asked — and then briefly '
        . 'tell them you\'ll remember it. Do not save trivia, temporary details, or clearly '
        . 'sensitive information without a clear reason, and do not re-save something you already '
        . 'know. Use what you know about the user to help them, but do not recite it back unprompted.';

    /** Meta / "what can you do" triggers → inject the capability summary that turn. */
    private const HELP_TRIGGERS = [
        'what can you do', 'what do you do', 'what can i ask', 'what are you able',
        'your capabilities', 'what features', 'how can you help', 'what can you help',
        'help me with', 'who are you', 'what are you',
        // Danish
        'hvad kan du', 'kan du hjælpe', 'hvad kan jeg', 'dine funktioner', 'hvem er du',
    ];

    private const CAPABILITIES =
        'If the user asks what you can do or how you can help, summarise these areas briefly and in '
        . 'their own language: workouts (log sets, review history and personal records); Google '
        . 'Calendar (read, add and delete events); shared shopping lists with their partner (named '
        . 'lists, add and check off items); weather in Denmark (current conditions and a forecast, '
        . 'from DMI); work-time tracking (clock in/out — automatically via an iPhone location '
        . 'automation they can set up, or manually — and hours worked today/this week); remembering '
        . 'personal facts about them; tracking business expenses/receipts (snap a photo or just tell '
        . 'you the amount — the app reads vendor, amount, VAT and date and they confirm); email '
        . '(once a mailbox is connected: check and search their inbox, summarise a message, and draft '
        . 'replies for them to review — sending is currently off); a backlog '
        . 'of their ideas for developing the app further ("for later: …"); their vinyl record '
        . 'collection with taste-based recommendations; '
        . 'a personal gift wishlist; connecting with other people to share data; and setting their '
        . 'display name and standing preferences.';

    /** A renderable card (e.g. a workout plan) emitted by a tool this turn, for the UI. */
    private ?array $lastRender = null;

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
    /** The renderable card emitted during the last handle() call, if any. */
    public function lastRender(): ?array
    {
        return $this->lastRender;
    }

    /** The current turn's card as JSON for persistence, or null if none. */
    private function lastRenderJson(): ?string
    {
        return $this->lastRender !== null
            ? (string) json_encode($this->lastRender, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
    }

    /**
     * @param array{lat: float, lon: float}|null $location optional device location (browser geolocation)
     */
    public function handle(int $userId, int $conversationId, string $userMessage, ?array $location = null): string
    {
        $this->conversations->addMessage($conversationId, 'user', $userMessage);
        $this->lastRender = null;

        $contents     = $this->buildContents($conversationId);
        // Send only the tools relevant to this message (falls back to all if unsure).
        // Include a little prior context so keyword-less follow-ups ("and tomorrow?")
        // keep the previous turn's domain tools available.
        $declarations = ToolSelector::select(
            $this->tools->declarations(),
            $userMessage,
            $this->recentUserContext($contents)
        );
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
                // Persist any card this turn produced with the reply, so reopening the
                // conversation can re-render the interactive widget (not just text).
                $this->conversations->addMessage($conversationId, 'assistant', $reply, null, $this->lastRenderJson());
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

                // A tool can attach a renderable card for the UI (last one wins). Strip it
                // from the result so the model doesn't re-list the plan as text — the widget
                // is the display; the model just gives a short intro.
                if (is_array($result) && isset($result['_render'])) {
                    if (is_array($result['_render'])) {
                        $this->lastRender = $result['_render'];
                    }
                    unset($result['_render']);
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

        // On a "what can you do?"-type question, add the capability summary so the
        // assistant can describe itself reliably (tool declarations alone are narrowed).
        $lc = mb_strtolower($userMessage);
        foreach (self::HELP_TRIGGERS as $trigger) {
            if (str_contains($lc, $trigger)) {
                $system .= "\n\n" . self::CAPABILITIES;
                break;
            }
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
    /**
     * The previous user turn(s), for tool-selection context — the current message
     * (the last user entry) is excluded so it isn't double-counted. Returns the
     * last up-to-2 prior user messages joined, capped in length.
     *
     * @param array<int, array{role:string, parts:array<int,array{text?:string}>}> $contents
     */
    private function recentUserContext(array $contents): string
    {
        $userTexts = [];
        foreach ($contents as $c) {
            if (($c['role'] ?? '') === 'user') {
                $userTexts[] = (string) ($c['parts'][0]['text'] ?? '');
            }
        }
        array_pop($userTexts); // drop the current message

        $recent = array_slice($userTexts, -2);

        return mb_substr(trim(implode(' ', $recent)), -400);
    }

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
