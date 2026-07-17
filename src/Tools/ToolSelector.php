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
            'create_workout_plan', 'get_workout_plan', 'get_week_plan', 'check_off_exercise',
            'uncheck_exercise', 'add_plan_exercise', 'remove_plan_exercise', 'delete_workout_plan',
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
            'get_current_weather', 'get_weather_forecast',
        ],
        'worktime' => [
            'get_work_hours', 'log_work_event', 'delete_work_event', 'get_work_tracking_setup',
        ],
        'worklog' => [
            'log_work_time', 'get_work_log', 'export_work_log',
        ],
        'devideas' => [
            'note_dev_idea', 'list_dev_ideas', 'remove_dev_idea',
        ],
        'receipts' => [
            'add_expense', 'update_receipt', 'delete_receipt', 'get_expenses', 'export_expenses_csv',
        ],
        'email' => [
            'get_emails', 'read_email', 'draft_email', 'send_email', 'list_email_accounts',
        ],
        'cycle' => [
            'log_period', 'get_cycle_status', 'remove_period', 'get_connected_cycle', 'log_cycle_day',
        ],
        'settings' => [
            'get_settings', 'update_setting',
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
            // planning
            'workout plan', 'training plan', 'my plan', 'workout program', 'training program', 'routine', 'session',
            'schedule my', "what's left", 'remaining', 'done with', 'checklist', 'tick off',
            // Danish
            'træn', 'motion', 'løft', 'øvelse', 'bænkpres', 'dødløft', 'markløft',
            'gentagelser', 'kropsvægt', 'fitness',
            'træningsplan', 'træningsprogram', 'rutine', 'skema', 'færdig med', 'mangler jeg', 'i dag skal',
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
            // To-do / task lists are named shared lists too (same tools, checklist card).
            'to-do', 'todo', 'to-do list', 'task list', 'checklist', 'check list',
            // Danish (køb also catches indkøb/købe/køber)
            'køb', 'indkøb', 'dagligvarer', 'mangler', 'skal bruge', 'løbet tør', 'kryds af',
            'på listen', ' liste', 'streg', 'huskeliste', 'gøremål', 'opgaveliste', 'tjekliste', 'to-do liste',
        ],
        'calendar' => [
            'calendar', 'event', 'schedule', 'appointment', 'meeting', ' meet', 'busy', ' free ', 'availab',
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
            'inviter', 'invitation', 'ny bruger', 'opret', 'tilmeld',
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
            'vejr', 'vejrudsigt', 'udsigt', 'regn', 'temperatur', 'grader', 'blæs', 'koldt',
            ' kold', 'varmt', ' sol ', 'solskin', 'sne', 'byger', 'paraply',
        ],
        // "work" alone is avoided (it's inside "workout"); use specific phrases.
        'worktime' => [
            ' clock', 'clock in', 'clock out', 'clocked', 'clock-in', 'clock-out', 'work hours', 'hours worked',
            'worked ', 'at work', 'left work', 'arrived at work', 'timesheet', 'time tracking',
            'on the clock', 'still clocked', 'when did i arrive', 'how long have i worked',
            'punch', 'punched', 'punch in', 'punch out',
            // Danish (arbejd* covers arbejde/arbejdstid/arbejdstimer; stempl* covers stemple/stempling)
            'arbejd', 'på arbejde', 'stempl', 'tidsregistrering', 'mødetid', 'arbejdstid',
        ],
        // What I DID at work (free-text log per job), distinct from clock-in hours above.
        'worklog' => [
            'work log', 'worklog', 'what did i do', 'what i did', 'what i got done', 'log what',
            'log my work', 'log that i', ' at dtu', ' at dsb', ' dtu', ' dsb',
            // Danish
            'arbejdslog', 'hvad lavede jeg', 'hvad har jeg lavet', 'hvad jeg lavede', 'loggede',
            'log mit arbejde', 'log hvad', 'på dtu', 'på dsb', 'noter hvad jeg',
        ],
        'devideas' => [
            'for later', 'for the backlog', 'to the backlog', 'add to backlog', 'backlog', 'dev idea',
            'feature idea', 'app idea', 'idea for the app', 'note this idea', 'note an idea',
            'improvement idea', 'build later', 'develop', 'my ideas', 'noted ideas',
            // Danish (udviklingside* covers udviklingsidé/-ideer plain-e; udviklingsidé has the
            // accented é so it needs its own entry; assistenten skal = "the assistant should …")
            'til senere', 'til backlog', 'ide til app', 'app-ide', 'funktion senere', 'mine ideer',
            'udviklingside', 'udviklingsidé', 'idé til assistent', 'ide til assistent',
            'gem denne idé', 'gem denne ide', 'gem idé', 'gem ide', 'assistenten skal', 'noteret idé',
        ],
        'receipts' => [
            'expense', 'expenses', 'receipt', 'receipts', 'moms', 'vat', 'deductible', 'write off',
            'write-off', 'i paid', 'paid for', 'business cost', 'reimburse', 'log a cost',
            'how much have i spent', 'have i spent', 'what have i spent', 'spending', 'my spend',
            'export', 'csv', 'accountant', 'bookkeeping',
            // Danish
            'udgift', 'udgifter', 'kvittering', 'bilag', 'fradrag', 'regning', 'jeg betalte', 'moms',
            'hvad har jeg brugt', 'brugt på', 'regnskab', 'bogføring', 'eksport', 'revisor',
        ],
        'email' => [
            'email', 'e-mail', ' mail', 'inbox', 'gmail', 'outlook', 'hotmail', 'unread',
            'reply to', 'draft', 'compose', 'my messages', 'new mail', 'check my mail',
            // Danish (indbakke=inbox, ulæst=unread, skriv til=write to, svar på=reply to)
            'indbakke', 'ulæst', 'skriv en mail', 'skriv til', 'svar på mail', 'post fra', 'e-post',
        ],
        // Menstrual cycle tracking. " cycle" is space-padded so it doesn't match inside
        // bicycle/recycle/motorcycle; "period" is common enough in context to include.
        'cycle' => [
            'period', 'menstru', ' cycle', 'my cycle', 'cycle day', 'ovulation', 'ovulating',
            'fertile', 'fertility', 'luteal', 'follicular', ' pms', 'cramps', 'next period',
            'time of the month', 'am i fertile',
            // Danish (menstruation/-scyklus, periode, cyklus, ægløsning, frugtbar, mensen)
            'menstruation', 'menstruationscyklus', 'periode', 'cyklus', 'ægløsning', 'frugtbar',
            'frugtbarhed', 'mensen', 'min menstruation', 'min periode', 'ægløsn',
            // mood/energy day logging (ID 4)
            'mood', 'my energy', 'energy level', 'energy is', 'how i feel', 'feel today', 'exhausted',
            'drained', 'humør', 'humor', 'energi', 'drænet', 'jeg føler',
        ],
        'settings' => [
            'setting', 'settings', 'preference', 'configure', 'which calendar', 'work calendar',
            'use my calendar', 'change calendar', 'calendar for work', 'calendar name',
            // Danish
            'indstilling', 'indstillinger', 'konfigurer', 'foretrukne', 'hvilken kalender',
            'arbejdskalender', 'brug min kalender', 'kalender til arbejde', 'kalendernavn',
        ],
    ];

    /**
     * Returns the subset of $declarations relevant to $message (or all of them if
     * nothing matched).
     *
     * $recentContext is a little prior conversation (e.g. the last user message or
     * two) so a keyword-less follow-up inherits its domain: "how's the weather?"
     * then "and tomorrow?" should still offer the weather tools, not just calendar.
     * Extra tools from stale context are harmless (the class only ever narrows when
     * confident); dropping a needed tool is the failure we avoid.
     *
     * @param array<int, array<string, mixed>> $declarations
     * @return array<int, array<string, mixed>>
     */
    public static function select(array $declarations, string $message, string $recentContext = ''): array
    {
        $groups = self::matchGroups($message, $recentContext);

        if ($groups === []) {
            return $declarations; // ambiguous → send everything (safe)
        }

        $allowed = [];
        foreach ($groups as $group) {
            foreach (self::GROUPS[$group] as $name) {
                $allowed[$name] = true;
            }
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

    /**
     * The domain groups whose keywords match the message (+ recent context). Empty
     * means nothing matched — the caller then falls back to all tools. Kept separate
     * from select() so routing can be unit-tested (bin/routing-test.php) and logged
     * per turn for observability.
     *
     * @return array<int, string> matched group names (in GROUPS order)
     */
    public static function matchGroups(string $message, string $recentContext = ''): array
    {
        $text = ' ' . mb_strtolower($message . ' ' . $recentContext) . ' ';

        $groups = [];
        foreach (self::KEYWORDS as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $groups[] = $group;
                    break;
                }
            }
        }

        return $groups;
    }

    /** @return array<int, string> all group names (for tests / tooling). */
    public static function groupNames(): array
    {
        return array_keys(self::GROUPS);
    }
}
