<?php

declare(strict_types=1);

namespace App\Email;

/**
 * Mail is full of text that is not valid UTF-8 (no charset declared, a wrong one, Latin-1 / Windows-1252 bytes in a "utf-8" body). One such
 * string makes json_encode() fail — and a failed encode is an empty response. This turns any string into valid UTF-8: Windows-1252 first
 * (what undeclared Western text almost always is), else the invalid bytes are dropped.
 */
final class Utf8
{
    public static function clean(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $conv = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (is_string($conv) && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) {
            return $conv;
        }

        return (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }

    /** Recursively, for a list of messages / a card. @param mixed $v @return mixed */
    public static function deep(mixed $v): mixed
    {
        if (is_string($v)) {
            return self::clean($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = self::deep($x);
            }
        }

        return $v;
    }
}
