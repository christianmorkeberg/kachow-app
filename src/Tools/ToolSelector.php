<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Chooses a relevant subset of tool declarations for a given user message, so we
 * don't send all ~24 tools on every request (Google's guidance is 10–20; a big
 * flat list hurts selection accuracy and costs tokens).
 *
 * Strategy: deterministic keyword routing by domain group. Matched groups are
 * sent; if NOTHING matches (ambiguous message, e.g. "delete that one"), fall back
 * to ALL tools. This only ever narrows when confident — extra tools (false
 * positives) are harmless; dropping a needed tool (false negative) is what we
 * avoid, so the fallback is deliberately generous.
 *
 * Cross-user read tools live in their domain group, so a question about a
 * connection's data ("how much did Alex squat?") pulls them in via the domain.
 */
final class ToolSelector
{
    /** group => tool names */
    private const GROUPS = [
        'workouts' => [
            'log_workout', 'get_workout_history', 'update_workout', 'delete_workout',
            'get_connected_workouts',
        ],
        'wishlist' => [
            'add_wishlist_item', 'get_wishlist', 'update_wishlist_item', 'delete_wishlist_item',
            'get_connected_wishlist',
        ],
        'calendar' => [
            'get_calendar_events', 'insert_calendar_event', 'delete_calendar_event', 'list_calendars',
            'get_connected_calendar',
        ],
        'instructions' => [
            'remember_instruction', 'get_instructions', 'forget_instruction',
        ],
        'connections' => [
            'send_connection_request', 'list_connections', 'accept_connection_request',
            'remove_connection', 'update_connection_sharing',
        ],
        'admin' => [
            'create_invite',
        ],
        'vinyls' => [
            'add_vinyl', 'get_vinyls', 'rate_vinyl', 'update_vinyl', 'remove_vinyl',
        ],
    ];

    /**
     * group => trigger substrings (lowercased). Substring match errs toward
     * inclusion, but avoid substrings that appear inside unrelated common words
     * (e.g. "row" in "tomorrow") — a spurious match wrongly narrows and suppresses
     * the all-fallback. Short/risky words are space-padded to act as whole words.
     */
    private const KEYWORDS = [
        'workouts' => [
            'workout', 'exercise', 'gym', 'squat', 'bench', 'deadlift', 'lunge', 'curl',
            'pull-up', 'pullup', 'push-up', 'pushup', 'rowing', 'overhead press', 'shoulder press',
            'leg press', 'chest press', 'bicep', 'tricep', 'reps', ' rep ', ' lift ', 'lifted',
            'lifting', 'weightlift', 'personal record', ' pr ', ' 1rm', 'training',
        ],
        'wishlist' => [
            'wish', ' buy ', 'gift', 'shopping', 'purchase', 'my list', 'price', 'priority',
        ],
        'calendar' => [
            'calendar', 'event', 'schedule', 'appointment', 'meeting', 'busy', ' free ', 'availab',
            'remind', 'agenda', 'plans', 'today', 'tomorrow', 'tonight', 'this week', 'next week',
            'weekend', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        ],
        'instructions' => [
            'remember', 'from now on', 'always ', 'prefer', 'forget', 'instruction', 'by default',
        ],
        'connections' => [
            'connect', 'connection', 'buddy', 'partner', 'share my', 'share their', 'sharing',
            ' accept', 'request', 'disconnect', 'friend',
        ],
        'admin' => [
            'invite', 'new user', 'add user', 'create account', 'sign up', 'signup',
        ],
        'vinyls' => [
            'vinyl', 'vinyls', ' lp ', ' album', 'turntable', 'pressing', 'discogs', 'listened',
            'my collection', 'record collection', ' genre', 'artist',
        ],
    ];

    /**
     * Returns the subset of $declarations relevant to $message (or all of them if
     * nothing matched).
     *
     * @param array<int, array<string, mixed>> $declarations
     * @return array<int, array<string, mixed>>
     */
    public static function select(array $declarations, string $message): array
    {
        $text = ' ' . mb_strtolower($message) . ' ';

        $allowed = [];
        foreach (self::KEYWORDS as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    foreach (self::GROUPS[$group] as $name) {
                        $allowed[$name] = true;
                    }
                    break;
                }
            }
        }

        if ($allowed === []) {
            return $declarations; // ambiguous → send everything (safe)
        }

        $subset = array_values(array_filter(
            $declarations,
            static fn (array $d): bool => isset($allowed[$d['name'] ?? ''])
        ));

        // Safety net: if filtering somehow left nothing, send all.
        return $subset === [] ? $declarations : $subset;
    }
}
