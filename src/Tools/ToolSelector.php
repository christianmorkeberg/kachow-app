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
    /**
     * Tools offered on EVERY request, even when the message narrows to one domain,
     * so the assistant can proactively capture a personal fact in any conversation.
     */
    private const ALWAYS = ['remember_about_me'];

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
        'shopping' => [
            'list_shopping_lists', 'get_shopping_list', 'add_to_shopping_list', 'check_off_item',
            'uncheck_item', 'remove_from_shopping_list', 'clear_checked_items', 'delete_shopping_list',
        ],
        'calendar' => [
            'get_calendar_events', 'insert_calendar_event', 'delete_calendar_event', 'list_calendars',
            'get_connected_calendar',
        ],
        'instructions' => [
            'remember_instruction', 'get_instructions', 'forget_instruction',
        ],
        'profile' => [
            'set_my_name',
        ],
        'memory' => [
            'remember_about_me', 'get_about_me', 'update_about_me', 'forget_about_me',
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
            'recommend_vinyl', 'assess_vinyl',
        ],
        'weather' => [
            'get_current_weather',
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
            // Danish
            'træn', 'motion', 'løft', 'øvelse', 'bænkpres', 'dødløft', 'markløft',
            'gentagelser', 'kropsvægt', 'fitness',
        ],
        // Personal wishlist is explicit-only now — the everyday "list"/"buy"/"shopping"
        // words route to the shared shopping list instead.
        'wishlist' => [
            'wishlist', 'wish list', 'gift', 'gifts',
            // Danish (" gave" avoids matching opgave/udgave; also catches gaver/gavekort)
            'ønskeliste', 'ønskeseddel', ' gave',
        ],
        'shopping' => [
            'shopping', 'grocer', 'the list', 'our list', 'shared list', 'a list', 'shopping list',
            ' buy ', 'need to buy', 'we need', 'pick up', 'picked up', 'ran out', 'cross off',
            'check off', 'tick off', 'checked off', 'clear the list', 'on the list', 'off the list',
            // Danish (køb also catches indkøb/købe/køber)
            'køb', 'indkøb', 'dagligvarer', 'mangler', 'skal bruge', 'løbet tør', 'kryds af',
            'på listen', ' liste', 'streg',
        ],
        'calendar' => [
            'calendar', 'event', 'schedule', 'appointment', 'meeting', 'busy', ' free ', 'availab',
            'remind', 'agenda', 'plans', 'today', 'tomorrow', 'tonight', 'this week', 'next week',
            'weekend', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            // Danish
            'kalender', 'aftale', 'møde', 'begivenhed', 'påmind', 'i dag', 'i morgen', 'i aften',
            'i weekenden', 'næste uge', 'denne uge', 'travlt', 'ledig', 'planer',
            'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag', 'søndag',
        ],
        'instructions' => [
            'remember', 'from now on', 'always ', 'prefer', 'forget', 'instruction', 'by default',
            // Danish
            'husk', 'fra nu af', 'foretræk', 'glem', 'som standard', 'altid',
        ],
        'profile' => [
            'call me ', 'my name', 'change my name', 'rename me', 'i am called', "i'm called",
            // Danish
            'kald mig', 'mit navn', 'skift mit navn', 'omdøb', 'jeg hedder',
        ],
        'memory' => [
            'about me', 'know about me', 'what do you know', 'my profile', 'remember that',
            'remember this', 'forget that', 'you know about', 'my details', 'who i am', 'note that',
            // Danish
            'om mig', 'hvad ved du', 'ved du om mig', 'husk at jeg', 'glem at', 'hvem er jeg',
            'mine oplysninger', 'min profil', 'noter at',
        ],
        'connections' => [
            'connect', 'connection', 'buddy', 'partner', 'share my', 'share their', 'sharing',
            ' accept', 'request', 'disconnect', 'friend',
            // Danish
            'forbind', 'del med', 'deler', 'anmodning', 'accepter', 'afbryd', 'kæreste', 'makker',
        ],
        'admin' => [
            'invite', 'new user', 'add user', 'create account', 'sign up', 'signup',
            // Danish
            'inviter', 'invitation', 'ny bruger', 'opret konto', 'tilmeld',
        ],
        'vinyls' => [
            'vinyl', 'vinyls', ' lp ', ' album', 'turntable', 'pressing', 'discogs', 'listened',
            'my collection', 'record collection', ' genre', 'artist',
            'recommend', 'similar to', 'listen to next', 'what should i listen', 'my taste',
            'a fit for', 'put on next', 'should i buy this record',
            // Danish
            'plade', 'pladesamling', 'lytte', 'kunstner', 'anbefal',
        ],
        'weather' => [
            'weather', 'forecast', 'temperature', ' rain', 'raining', 'sunny', ' wind ', 'windy',
            'how cold', 'how hot', 'umbrella', 'degrees outside',
            // Danish
            'vejr', 'regn', 'temperatur', 'grader', 'blæs', 'koldt', ' kold', 'varmt',
            ' sol ', 'solskin', 'sne', 'byger', 'paraply',
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

        // Keep proactive-memory capture available even on narrowed turns.
        foreach (self::ALWAYS as $name) {
            $allowed[$name] = true;
        }

        $subset = array_values(array_filter(
            $declarations,
            static fn (array $d): bool => isset($allowed[$d['name'] ?? ''])
        ));

        // Safety net: if filtering somehow left nothing, send all.
        return $subset === [] ? $declarations : $subset;
    }
}
