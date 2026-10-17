<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What changed between two versions of a page's text, line by line (one line per visible block,
 * see Web\PageText). Pure. Order-insensitive on purpose: a block that only moved is not news;
 * a new line ("Back in stock", a new job ad, a different price) is.
 */
final class TextDiff
{
    public const MAX_LINES     = 40;
    public const MAX_LINE_CHARS = 300;

    /**
     * @return array{changed: bool, added: list<string>, removed: list<string>, added_total: int, removed_total: int}
     */
    public static function diff(string $old, string $new): array
    {
        $count = static function (string $s): array {
            $c = [];
            foreach (explode("\n", $s) as $l) {
                $l = trim($l);
                if ($l !== '') {
                    $c[$l] = ($c[$l] ?? 0) + 1;
                }
            }

            return $c;
        };
        $a = $count($old);
        $b = $count($new);

        $added = $removed = [];
        foreach ($b as $line => $n) {
            for ($i = 0; $i < $n - ($a[$line] ?? 0); $i++) {
                $added[] = $line;
            }
        }
        foreach ($a as $line => $n) {
            for ($i = 0; $i < $n - ($b[$line] ?? 0); $i++) {
                $removed[] = $line;
            }
        }
        $cut = static fn (array $lines): array => array_map(
            static fn (string $l): string => mb_strlen($l) > self::MAX_LINE_CHARS ? mb_substr($l, 0, self::MAX_LINE_CHARS) . '…' : $l,
            array_slice($lines, 0, self::MAX_LINES)
        );

        return [
            'changed'       => $added !== [] || $removed !== [],
            'added'         => $cut($added),
            'removed'       => $cut($removed),
            'added_total'   => count($added),
            'removed_total' => count($removed),
        ];
    }
}
