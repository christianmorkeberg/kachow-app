<?php

declare(strict_types=1);

namespace App\Assistant;

use RuntimeException;

/**
 * Thrown by GeminiClient when Google refuses because the account has no money/credits left ("Your prepayment credits are depleted",
 * a billing problem). Trying another model does not help, so this is NOT a RateLimitException: it goes straight up to chat.php, which tells
 * the user in plain words (and the page flashes it) instead of "Something went wrong".
 */
final class AiCreditsException extends RuntimeException
{
    /** Does Gemini's error text say the account is out of credits / has a billing problem? */
    public static function matches(?string $apiMessage): bool
    {
        return $apiMessage !== null && preg_match('/prepay|credits?\b.*(deplet|exhaust|insufficient|run out)|(deplet|exhaust).*credits?|billing|insufficient (funds|credit)|payment required/i', $apiMessage) === 1;
    }

    /** What the user sees (English, or Danish when they wrote Danish). Names the fix: top up the Gemini account. */
    public static function userText(bool $danish): string
    {
        return $danish
            ? '⚠️ AI-kreditterne er brugt op, så jeg kan ikke svare lige nu. Fyld Gemini-kontoen op (ai.studio/projects → billing), og prøv igen.'
            : '⚠️ The AI credits are used up, so I can\'t answer right now. Top up the Gemini account (ai.studio/projects → billing), then try again.';
    }
}
