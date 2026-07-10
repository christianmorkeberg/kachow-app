<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Data\Conversations;

/**
 * Generates a short, human title for a conversation from its opening messages,
 * using a cheap model. Kept out of Data/ (which is SQL-only) — this is the bit of
 * business logic that talks to Gemini and then stores the result.
 */
final class ConversationTitler
{
    private const SYSTEM =
        'You write a very short title (3 to 6 words) summarising what a chat is about. '
        . 'Reply with ONLY the title — Title Case, no surrounding quotes, no trailing punctuation, '
        . 'no prefix like "Title:". Use the same language as the conversation.';

    public function __construct(
        private GeminiClient $gemini,
        private Conversations $conversations,
    ) {
    }

    /**
     * Ensures a conversation (owned by $userId) has a title, generating one if
     * missing. Returns the title, or null if it couldn't be produced.
     */
    public function ensure(int $userId, int $conversationId): ?string
    {
        $existing = $this->conversations->title($userId, $conversationId);
        if ($existing !== null && $existing !== '') {
            return $existing;
        }
        if ($this->conversations->ownerId($conversationId) !== $userId) {
            return null; // not this user's conversation
        }

        $opening = $this->openingText($conversationId);
        if ($opening === '') {
            return null;
        }

        try {
            $models   = $this->gemini->models();
            $cheapest = end($models) ?: null;   // last in the chain is the lightest
            $response = $this->gemini->generate(
                [['role' => 'user', 'parts' => [['text' => $opening]]]],
                [],
                self::SYSTEM,
                $cheapest,
            );
            $title = $this->clean(GeminiClient::extractText($response));
        } catch (\Throwable $e) {
            return null; // rate-limited / error — leave untitled, the list falls back to a preview
        }

        if ($title === '') {
            return null;
        }
        $this->conversations->setTitle($userId, $conversationId, $title);

        return $title;
    }

    /** First user + first assistant message, trimmed — enough to title the chat. */
    private function openingText(int $conversationId): string
    {
        $user = '';
        $asst = '';
        foreach ($this->conversations->messages($conversationId, 12) as $m) {
            $role = (string) $m['role'];
            if ($role === 'user' && $user === '') {
                $user = (string) $m['content'];
            } elseif ($role === 'assistant' && $asst === '') {
                $asst = (string) $m['content'];
            }
            if ($user !== '' && $asst !== '') {
                break;
            }
        }

        $text = trim($user . "\n" . $asst);

        return mb_substr($text, 0, 1000);
    }

    private function clean(string $title): string
    {
        $title = trim(explode("\n", trim($title))[0]);              // first line only
        $title = trim($title, " \t\"'“”‘’");                        // strip wrapping quotes
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = rtrim($title, " .!?:;,");

        return mb_substr($title, 0, 120);
    }
}
