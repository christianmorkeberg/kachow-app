<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Thrown by GeminiClient when the API reports the model is rate-limited (HTTP 429)
 * or transiently overloaded (HTTP 503). The AssistantLoop catches this to fall back
 * to the next model in the chain.
 *
 * Carries the HTTP status (429 vs 503) and Gemini's own error.message so the loop can
 * tell a transient overload from a quota/billing wall and surface the real reason in
 * diagnostics instead of a generic "try again in a few seconds".
 */
final class RateLimitException extends RuntimeException
{
    public function __construct(string $message, private int $statusCode = 0, private ?string $apiMessage = null)
    {
        parent::__construct($message);
    }

    /** HTTP status that triggered this: 429 (quota/rate) or 503 (overloaded), or 0 if unknown. */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** Gemini's own error.message (the real reason), or null if the response carried none. */
    public function apiMessage(): ?string
    {
        return $this->apiMessage;
    }
}
