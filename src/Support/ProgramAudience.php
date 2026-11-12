<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Who a training day is for, as a person says it: "both", "me" / "only me", or the other person's name. Pure — the names of the people on the programme come in.
 */
final class ProgramAudience
{
    /**
     * @param array<int, array{name?:?string, email?:?string}> $people the people on the programme, by user id (the speaker included)
     * @return int|null|false null = both; an id = that one person; false = not understood
     */
    public static function resolve(string $for, int $me, array $people): int|null|false
    {
        $f = mb_strtolower(trim($for));
        if ($f === '' || in_array($f, ['both', 'begge', 'all', 'everyone', 'alle', 'us', 'os', 'shared'], true)) {
            return null;
        }
        if (in_array($f, ['me', 'only me', 'mig', 'kun mig', 'myself', 'just me', 'only mine'], true)) {
            return $me;
        }
        foreach ($people as $id => $p) {
            $name  = mb_strtolower(trim((string) ($p['name'] ?? '')));
            $first = $name !== '' ? explode(' ', $name)[0] : '';
            $local = strtok(mb_strtolower((string) ($p['email'] ?? '')), '@') ?: '';
            if ($f === $name || ($first !== '' && ($f === $first || $f === 'only ' . $first || $f === 'kun ' . $first)) || ($local !== '' && $f === $local)) {
                return (int) $id;
            }
        }

        return false;
    }

    /** What to show for a day's audience: "Both", or the person's first name. @param array<int, array{name?:?string, email?:?string}> $people */
    public static function label(?int $audience, array $people, string $both = 'Both'): string
    {
        if ($audience === null) {
            return $both;
        }
        $n = trim((string) ($people[$audience]['name'] ?? ''));

        return $n !== '' ? explode(' ', $n)[0] : '?';
    }
}
