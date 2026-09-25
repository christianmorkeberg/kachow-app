<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Thrown by GeminiClient when a generateContent call doesn't answer within its time cap
 * (cURL timeout). Kept distinct from RateLimitException (a quota/overload answer) and from
 * a plain RuntimeException (e.g. a model rejecting the thinking config), so the loop can
 * retry a stalled call once instead of misreading it — see AssistantLoop::timedGenerate().
 */
final class GeminiTimeoutException extends RuntimeException
{
}
