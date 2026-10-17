<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Readable text from a fetched page. Pure: scripts, styles and markup out, block elements
 * become line breaks, entities decoded, whitespace collapsed — one line per visible block,
 * which is also what the change diff (Support\TextDiff) compares.
 */
final class PageText
{
    /** Below this many characters of text, a script-heavy page is taken to render in JavaScript. */
    public const MIN_TEXT_CHARS = 200;

    /**
     * @return array{title: string, text: string, needs_js: bool}
     */
    public static function extract(string $body, string $contentType = 'text/html'): array
    {
        $ct = strtolower($contentType);
        if (!str_contains($ct, 'html') && !str_contains($ct, 'xml')) {
            // Plain text or JSON: keep as is, just tidy the whitespace.
            return ['title' => '', 'text' => self::tidy($body), 'needs_js' => false];
        }

        $title = '';
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m) === 1) {
            $title = self::tidy(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $scripts = preg_match_all('~<script\b~i', $body);

        $html = preg_replace('~<!--.*?-->~s', ' ', $body) ?? $body;
        $html = preg_replace('~<(script|style|noscript|svg|template|iframe|head)\b[^>]*>.*?</\1\s*>~is', ' ', $html) ?? $html;
        $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</?(p|div|section|article|header|footer|main|aside|nav|li|ul|ol|tr|table|h[1-6]|dt|dd|blockquote|pre|figure|figcaption|form|label|option)\b[^>]*>~i', "\n", $html) ?? $html;
        $html = preg_replace('~</(td|th)\s*>~i', ' · ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::tidy($text);

        return [
            'title'    => $title,
            'text'     => $text,
            'needs_js' => mb_strlen($text) < self::MIN_TEXT_CHARS && $scripts >= 3,
        ];
    }

    /** One trimmed line per block, inner whitespace collapsed, empty and repeated blank lines dropped. */
    private static function tidy(string $s): string
    {
        $s     = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $s);
        $lines = [];
        foreach (explode("\n", $s) as $line) {
            $line = trim((string) preg_replace('/[ \t\f\v]+/u', ' ', $line));
            if ($line !== '' && $line !== '·') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
