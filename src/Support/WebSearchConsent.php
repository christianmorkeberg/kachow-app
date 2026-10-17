<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether the user has just asked for, or agreed to, a web search. The assistant never goes to the
 * internet on its own: the web_search tool is only handed to the model on a turn where this says
 * yes, so "always ask first" is enforced here, not left to the prompt.
 *
 *  - an explicit request ("search the web for…", "google it", "søg på nettet efter…"),
 *  - a tap on the offer chip (its label is fixed: see CHIPS),
 *  - a bare "yes"/"ja" straight after the assistant offered a search.
 */
final class WebSearchConsent
{
    /** The labels the assistant uses on its offer chip; a tap sends exactly this text. */
    public const CHIPS = ['search the web', 'søg på nettet'];

    public static function granted(string $message, string $previousAssistant = ''): bool
    {
        $m = mb_strtolower(trim($message));
        if ($m === '') {
            return false;
        }
        if (in_array(rtrim($m, ".! \t"), self::CHIPS, true)) {
            return true;
        }

        $explicit = [
            // English
            '/\b(search|google|look\s*up|look\s*it\s*up|check|find|browse)\b.{0,40}\b(the\s+)?(web|online|internet|net)\b/u',
            '/\b(web|online|internet)\s+search\b/u',
            '/\bgoogle\s+(it|that|this|the|for|if|what|when|where|who|how|opening|hours)\b/u',
            '/^google\b/u',
            // Danish
            '/\bs(ø|oe)g(e)?\b.{0,40}\b(nettet|internettet|online|web|google)\b/u',
            '/\bs(ø|oe)g\s+(det\s+)?(op\s+)?(på|paa)\s+(nettet|internettet|google)\b/u',
            '/\bsl(å|aa)\s+(det\s+)?op\s+(på|paa|online)\b/u',
            '/\b(tjek|find)\b.{0,30}\b(online|på\s+nettet|paa\s+nettet|på\s+internettet)\b/u',
            '/\bgoogl(e|)\s+(det|dem|den|lige|åbningstid|abningstid)\b/u',
        ];
        foreach ($explicit as $re) {
            if (preg_match($re, $m) === 1) {
                return true;
            }
        }

        // A short yes, but only as the answer to an offer to search.
        if (preg_match('/^(yes|yeah|yep|sure|ok(ay)?|please( do)?|go ahead|do it|ja|jep|jo|gerne|ja tak|det vil jeg gerne|ja gerne)\b[\s.!]*$/u', $m) === 1) {
            $prev = mb_strtolower($previousAssistant);

            return preg_match('/\b(web|online|internet|nettet|internettet|search|søg|google)\b/u', $prev) === 1
                && preg_match('/\?\s*$/u', trim($prev)) === 1;
        }

        return false;
    }
}
