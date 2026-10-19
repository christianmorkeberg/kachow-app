<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Which commit is actually running, read straight from a repo's .git folder (no shell, no deploy
 * step): the two checkouts on the server (the webroot and the app) are moved to origin by the deploy
 * workflow, so HEAD and the time of its last move say whether a deploy landed. Stale servers have
 * looked identical to fresh ones before — this is how to tell.
 */
final class Version
{
    /**
     * HEAD of the repo at $dir: its full and short sha and when HEAD last moved (a Unix timestamp, from
     * the reflog — i.e. the deploy time), or null when $dir is not a git checkout.
     *
     * @return array{sha:string, short:string, at:?int}|null
     */
    public static function ofRepo(string $dir): ?array
    {
        $git = self::gitDir($dir);
        if ($git === null) {
            return null;
        }
        $head = self::read($git . '/HEAD');
        if ($head === null) {
            return null;
        }
        $sha = null;
        if (preg_match('/^ref:\s*(\S+)/', $head, $m) === 1) {
            $sha = self::resolveRef($git, $m[1]);
        } elseif (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
            $sha = $head;   // detached HEAD
        }
        if ($sha === null) {
            return null;
        }

        return ['sha' => $sha, 'short' => substr($sha, 0, 7), 'at' => self::movedAt($git, $head)];
    }

    /**
     * One line for the menu footer: "web 95b3234 · app c6e9109 · deployed 19 Oct 22:16", or null when
     * neither checkout can be read. $now is for tests.
     */
    public static function summary(string $webDir, string $appDir, ?string $tz = 'Europe/Copenhagen'): ?string
    {
        $parts = [];
        $latest = null;
        foreach (['web' => $webDir, 'app' => $appDir] as $label => $dir) {
            $v = self::ofRepo($dir);
            if ($v === null) {
                continue;
            }
            $parts[] = $label . ' ' . $v['short'];
            if ($v['at'] !== null && ($latest === null || $v['at'] > $latest)) {
                $latest = $v['at'];
            }
        }
        if ($parts === []) {
            return null;
        }
        if ($latest !== null) {
            $when    = (new DateTimeImmutable('@' . $latest))->setTimezone(new DateTimeZone($tz ?? 'UTC'));
            $parts[] = 'deployed ' . $when->format('j M H:i');
        }

        return implode(' · ', $parts);
    }

    /** The .git directory of $dir — also when .git is a file pointing elsewhere (a worktree). */
    private static function gitDir(string $dir): ?string
    {
        $dot = rtrim($dir, '/') . '/.git';
        if (is_dir($dot)) {
            return $dot;
        }
        $file = self::read($dot);
        if ($file !== null && preg_match('/^gitdir:\s*(.+)$/', $file, $m) === 1) {
            $target = trim($m[1]);
            $path   = str_starts_with($target, '/') ? $target : rtrim($dir, '/') . '/' . $target;

            return is_dir($path) ? $path : null;
        }

        return null;
    }

    private static function resolveRef(string $git, string $ref): ?string
    {
        $loose = self::read($git . '/' . $ref);
        if ($loose !== null && preg_match('/^[0-9a-f]{40}$/', $loose) === 1) {
            return $loose;
        }
        $packed = self::read($git . '/packed-refs');
        if ($packed !== null && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** When HEAD last moved: the last reflog entry, else the modified time of the ref/HEAD file. */
    private static function movedAt(string $git, string $head): ?int
    {
        $log = self::read($git . '/logs/HEAD');
        if ($log !== null) {
            $lines = array_values(array_filter(explode("\n", $log), static fn (string $l): bool => trim($l) !== ''));
            $last  = $lines === [] ? '' : $lines[count($lines) - 1];
            if (preg_match('/^[0-9a-f]{40} [0-9a-f]{40} .*? (\d{9,11}) [+-]\d{4}\t/', $last, $m) === 1) {
                return (int) $m[1];
            }
        }
        $files = [$git . '/HEAD'];
        if (preg_match('/^ref:\s*(\S+)/', $head, $m) === 1) {
            array_unshift($files, $git . '/' . $m[1], $git . '/packed-refs');
        }
        foreach ($files as $file) {
            $mtime = is_file($file) ? @filemtime($file) : false;
            if ($mtime !== false) {
                return $mtime;
            }
        }

        return null;
    }

    private static function read(string $path): ?string
    {
        $s = is_file($path) ? @file_get_contents($path) : false;

        return $s === false ? null : trim($s);
    }
}
