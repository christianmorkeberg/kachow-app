<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Thrown by GeminiClient when the API reports the model is rate-limited (HTTP 429)
 * or transiently overloaded (HTTP 503). The AssistantLoop catches this to fall back
 * to the next model in the chain.
 */
final class RateLimitException extends RuntimeException
{
}
