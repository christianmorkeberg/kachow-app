<?php

declare(strict_types=1);

namespace App\Email;

/**
 * The content part of an outgoing message: the plain-text body, or multipart/mixed (text + attachments) when the draft has attachments.
 * Providers put these header lines after their own (From / To / Subject …) and the body after the blank line.
 */
final class Mime
{
    /** @return array{headers: list<string>, body: string} */
    public static function content(EmailDraft $draft): array
    {
        $text = ['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', rtrim(chunk_split(base64_encode($draft->bodyText), 76, "\r\n"))];
        if ($draft->attachments === []) {
            return ['headers' => [$text[0], $text[1]], 'body' => $text[3] . "\r\n"];
        }

        $boundary = '=_kachow_' . bin2hex(random_bytes(12));
        $parts    = ['--' . $boundary, ...$text];
        foreach ($draft->attachments as $a) {
            $name    = self::safeName($a['filename']);
            $encoded = self::encodedName($name);
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . self::safeType($a['mime']) . '; name="' . $encoded . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $encoded . '"';
            $parts[] = '';
            $parts[] = rtrim(chunk_split(base64_encode($a['data']), 76, "\r\n"));
        }
        $parts[] = '--' . $boundary . '--';

        return ['headers' => ['Content-Type: multipart/mixed; boundary="' . $boundary . '"'], 'body' => implode("\r\n", $parts) . "\r\n"];
    }

    public static function safeName(string $name): string
    {
        $name = trim(str_replace(["\r", "\n", '"', '\\', '/'], '', $name));

        return $name !== '' ? $name : 'attachment';
    }

    /** RFC 2047 for a non-ASCII file name. */
    private static function encodedName(string $name): string
    {
        return preg_match('/[\x80-\xFF]/', $name) ? '=?UTF-8?B?' . base64_encode($name) . '?=' : $name;
    }

    private static function safeType(string $mime): string
    {
        return preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', $mime) === 1 ? $mime : 'application/octet-stream';
    }
}
