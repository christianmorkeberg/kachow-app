<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The same lift goes by different names — across people and languages ("Dødløft" / "Deadlift", "Pres" / "Overhead press"). A connected person logs under
 * THEIR names, so looking up another person's max by the name in your own plan found nothing ("kan ikke finde max"). This maps the common lifts to one
 * group key so a name finds its counterpart among the names someone actually logs. Deliberately small and conservative: only unambiguous barbell basics.
 */
final class ExerciseNames
{
    /** group key => the names (normalised) that mean it */
    private const GROUPS = [
        'deadlift'       => ['deadlift', 'dead lift', 'conventional deadlift', 'dødløft', 'doedloeft'],
        'romanian'       => ['romanian deadlift', 'rdl', 'rumænsk dødløft', 'rumaensk dødløft'],
        'bench'          => ['bench press', 'bench', 'flat bench', 'flat bench press', 'barbell bench press', 'bænkpres', 'bænk', 'baenkpres'],
        'squat'          => ['squat', 'squats', 'back squat', 'backsquat', 'barbell squat', 'knebøjning', 'knæbøjning', 'baksquat'],
        'overhead'       => ['overhead press', 'ohp', 'shoulder press', 'military press', 'standing press', 'strict press', 'pres', 'skulderpres', 'militærpres', 'stående pres'],
        'row'            => ['barbell row', 'bent over row', 'bent-over row', 'row', 'rows', 'stangroning', 'roning'],
        'pullup'         => ['pull-up', 'pull up', 'pullup', 'pullups', 'pull-ups', 'pull ups', 'chin-up', 'chin up', 'chinup', 'chins', 'chin-ups'],
    ];

    /** @return string|null the group key a name belongs to */
    public static function group(string $name): ?string
    {
        $n = TextMatch::normalize($name);
        foreach (self::GROUPS as $key => $names) {
            foreach ($names as $v) {
                if ($n === TextMatch::normalize($v)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Which of the names someone actually logs is the one asked for: the same name, the same lift by another name or language, or a close spelling.
     *
     * @param list<string> $known
     */
    public static function matchIn(string $wanted, array $known): ?string
    {
        $n = TextMatch::normalize($wanted);
        foreach ($known as $k) {
            if (TextMatch::normalize($k) === $n) {
                return $k;
            }
        }
        $g = self::group($wanted);
        if ($g !== null) {
            foreach ($known as $k) {
                if (self::group($k) === $g) {
                    return $k;
                }
            }
        }
        foreach ($known as $k) {
            if (TextMatch::similar($k, $wanted, 80.0)) {
                return $k;
            }
        }

        return null;
    }
}
