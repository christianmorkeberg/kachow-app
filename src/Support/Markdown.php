<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal, safe Markdown → HTML for assistant replies.
 *
 * Supports: headings (#/##/###), unordered (-, *, •) and ordered (1.) lists,
 * **bold** / __bold__, *italic* / _italic_, `inline code`, and [text](http…)
 * links. Everything is HTML-escaped first, so model/tool output can't inject
 * markup — only the tags this class emits are literal.
 */
final class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html  = '';
        $list  = null; // 'ul' | 'ol' | null

        $closeList = static function () use (&$html, &$list): void {
            if ($list !== null) {
                $html .= '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*[-*\x{2022}]\s+(.*)$/u', $line, $m) === 1) {
                if ($list !== 'ul') {
                    $closeList();
                    $html .= '<ul>';
                    $list = 'ul';
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m) === 1) {
                if ($list !== 'ol') {
                    $closeList();
                    $html .= '<ol>';
                    $list = 'ol';
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $closeList();

            if (preg_match('/^\s*(#{1,3})\s+(.*)$/', $line, $m) === 1) {
                $level = strlen($m[1]) + 2; // h3..h5
                $html .= '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $html .= '<p>' . self::inline($line) . '</p>';
        }

        $closeList();

        return $html;
    }

    private static function inline(string $text): string
    {
        // Escape first — the rest only ever adds our own tags.
        $s = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $s = preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => '<code>' . $m[1] . '</code>', $s) ?? $s;
        $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(^|[^*])\*([^*\n]+)\*(?!\*)/', '$1<em>$2</em>', $s) ?? $s;
        $s = preg_replace('/(^|[^_])_([^_\n]+)_(?!_)/', '$1<em>$2</em>', $s) ?? $s;

        // Links: text already escaped; the URL is validated to http(s).
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn (array $m): string =>
                '<a href="' . $m[2] . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>',
            $s
        ) ?? $s;

        return $s;
    }
}
