<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Live progress of one chat turn, handed from api/chat.php (writer, via
 * AssistantLoop::onProgress) to api/progress.php (reader, polled by the browser while it
 * waits). A tiny JSON file per turn in the system temp dir — no table, no migration — keyed
 * by user id + a random turn id the client generates, deleted when the turn ends. It holds
 * tool NAMES and status only, never arguments or content.
 */
final class TurnProgress
{
    private const DIR     = 'kachow-progress';
    private const MAX_AGE = 900; // seconds; anything older is a crashed turn's leftover

    public function __construct(private int $userId, private string $turnId)
    {
    }

    /** A client-supplied turn id is only accepted in this shape (no path tricks). */
    public static function validId(string $turnId): bool
    {
        return preg_match('/^[a-f0-9]{16,40}$/', $turnId) === 1;
    }

    /** @param array<string, mixed> $state */
    public function write(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $this->path() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, (string) json_encode($state + ['t' => time()])) !== false) {
            @rename($tmp, $this->path()); // atomic swap: a reader never sees half a file
        }
    }

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function delete(): void
    {
        @unlink($this->path());
    }

    /** Removes leftovers from turns that died before cleaning up (cheap; call occasionally). */
    public static function gc(): void
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < time() - self::MAX_AGE) {
                @unlink($f);
            }
        }
    }

    private function path(): string
    {
        return self::dir() . '/u' . $this->userId . '-' . $this->turnId . '.json';
    }

    private static function dir(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/' . self::DIR;
    }
}
