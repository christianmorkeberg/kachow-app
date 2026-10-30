<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The words of a slow chat turn that carries on in the background (api/chat.php + Detach): the interim "still working" message, the "it did not
 * work" one, and the push banner text for the late answer. One language per message, chosen from what the user wrote (Danish or English). Pure.
 */
final class SlowReply
{
    /** Danish when the text has æ/ø/å or a few very common Danish words. */
    public static function isDanish(string $text): bool
    {
        if (preg_match('/[æøåÆØÅ]/u', $text) === 1) {
            return true;
        }

        return preg_match('/\b(jeg|ikke|og|det|til|sæt|hvad|hvor|kan|du|vil|skal|tænd|sluk|min|mit|mine|er|har|på|af|en|et|med|for|som|nu|lige|gerne)\b/iu', $text) === 1
            && preg_match('/\b(the|and|is|are|what|how|please|can|turn|set|my|you|to|for|with)\b/iu', $text) !== 1;
    }

    /** What the browser is told when the turn has taken too long to wait for. */
    public static function interim(string $userMessage): string
    {
        return self::isDanish($userMessage)
            ? 'Det tager lidt længere end normalt — jeg er stadig i gang. Jeg skriver, så snart det er klar, eller hvis det ikke lykkes.'
            : 'This is taking a bit longer than usual — I\'m still on it. I\'ll write as soon as it is done, or if it does not work.';
    }

    /** What the user gets when the turn fails after the browser was told it was still working. */
    public static function failed(string $userMessage): string
    {
        return self::isDanish($userMessage)
            ? 'Det lykkedes desværre ikke at gøre det færdigt. Noget kan allerede være udført — tjek det, før du prøver igen.'
            : 'Sorry — I could not finish that. Something may already have been done, so check it before trying again.';
    }

    /** The push banner for a late answer: plain text, one short paragraph. */
    public static function pushBody(string $reply, int $max = 140): string
    {
        $t = (string) preg_replace('/\[\[\s*suggest\s*:.+?\]\]/is', '', $reply);
        $t = (string) preg_replace('/[*_`#>]+/u', '', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }
}
