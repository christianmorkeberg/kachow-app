<?php

declare(strict_types=1);

/**
 * Scheduled-task runner — run every MINUTE by cron (a task set for 13:00 fires at 13:00; with
 * nothing due a run is one indexed query).
 *
 * For each due task (set up via schedule_task), re-invokes the SAME AssistantLoop used
 * for live chat, feeding the task's stored instruction back in as if the user had just
 * typed it — with FULL tool access (wideTools) and a notify_me tool bound to that
 * task's own conversation, so it can push a notification if (and only if) it finds
 * something worth telling the user. Each task is claimed-and-rescheduled atomically
 * BEFORE it runs, so a slow or failing run can't double-fire or stall the schedule —
 * it just waits for its next regular slot (see ScheduledTasks::claimAndReschedule()).
 *
 * Builds the exact same tool/dependency graph kachow-web/api/chat.php builds per
 * request (there's no shorter factory) — reused across every due task in this run
 * (ToolRegistry/AssistantLoop aren't user-specific; only notify_me is re-bound per
 * task, right before each handle() call).
 */

// Crontab (kept out of the block comment above so a "* /" can't close it):
//   * * * * * php /home/kachowdk/assistant-app/bin/scheduled-tasks-cron.php >/dev/null 2>&1

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

use App\Assistant\AssistantLoop;
use App\Assistant\GeminiClient;
use App\Auth\GoogleOAuth;
use App\Data\ApiTokens;
use App\Data\BookkeepingAudit;
use App\Data\Calendar;
use App\Data\Connections;
use App\Data\Conversations;
use App\Data\CycleTracker;
use App\Data\DevIdeas;
use App\Data\Income;
use App\Data\Invites;
use App\Data\Memories;
use App\Data\OwnerDraws;
use App\Data\Receipts;
use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\Routines;
use App\Data\ScheduledTasks;
use App\Data\ShoppingLists;
use App\Data\UserInstructions;
use App\Data\Users;
use App\Data\UserSettings;
use App\Data\Vinyls;
use App\Data\Wishlist;
use App\Data\WorkEvents;
use App\Data\WorkLog;
use App\Data\WorkoutPlans;
use App\Data\Workouts;
use App\Email\EmailService;
use App\Mail\NativeMailer;
use App\Music\Discogs;
use App\Notify\NotificationTypes;
use App\Notify\Notifier;
use App\Notify\WebPush;
use App\Receipts\ReceiptStorage;
use App\Data\SystemTasks;
use App\Support\Household;
use App\Support\HomeNow;
use App\Support\PlaceNow;
use App\Support\RoutineGate;
use App\Support\RoutineSteps;
use App\Support\SystemTaskDispatcher;
use App\Support\SystemTaskRunner;
use App\Support\ToolMemory;
use App\Tools\NotifyMe;
use App\Tools\ToolRegistry;
use App\Weather\Dmi;

// The built-in cron jobs the dispatcher owns (see App\Data\SystemTasks) run when this script ends — after the
// user's tasks, so a long job never delays them, and also when it exits early below (no push configured).
// Jobs left on the cPanel entry are untouched: with none switched over this is one cheap query.
// `--requested-only`: started in the background by run_routine / a place trigger to run the waiting routine runs right away (no
// due tasks, no system jobs) — the per-minute run would pick them up anyway, a minute later.
$requestedOnly = in_array('--requested-only', $argv ?? [], true);
if (!$requestedOnly) {
    register_shutdown_function(static function (): void {
        try {
            SystemTaskDispatcher::runDue(new SystemTasks(), [SystemTaskRunner::class, 'run'], __DIR__);
        } catch (\Throwable $e) {
            error_log('scheduled-tasks-cron system tasks: ' . $e->getMessage());
        }
    });
}

// A task's notify_me is pointless without push configured, and this run is otherwise
// expensive (a real Gemini tool-calling turn per task) for no reachable payoff.
if (!WebPush::isConfigured()) {
    fwrite(STDERR, "scheduled-tasks-cron: VAPID not configured; nothing to do.\n");
    exit(0);
}

// Prepended to every run's instruction — the one guardrail on top of full tool access:
// a prompt-level reminder that nobody is here to catch a bad call, not a technical one
// (Christian explicitly chose full access; this just asks the model to be conservative
// about what "full access" should actually mean when no one's watching).
const UNATTENDED_PREAMBLE = "[Scheduled task — this is an unattended run, nobody is "
    . "watching live. Do ONLY what the instruction below specifically asks. If it's a "
    . "check/notify instruction, don't also send messages, delete anything, or take any "
    . "other action on your own initiative. ";

/** Appended per task: how the run should report back. */
const REPORT_QUIET = "Call notify_me only if there's something worth telling the user; "
    . "otherwise finish quietly without calling it. After notify_me, end with a few words at most — "
    . "the notification already holds the content.]";
const REPORT_ALWAYS = "When you have done what the instruction asks, call notify_me ONCE with a "
    . "short title and one sentence on what you did (e.g. which lights you turned on). If the "
    . "instruction's condition was not met and you did nothing, finish quietly without calling it. "
    . "If the home agent was unreachable (offline / no answer), do not notify: the run is retried "
    . "automatically. For any other failure, call notify_me to say what went wrong. After notify_me, end "
    . "with a few words at most — the notification already holds the content.]";

/** One retry when the home agent could not be reached: a Wi-Fi blip is gone within a minute or two. */
const RETRY_AFTER_SECONDS = 90;
const RETRY_PREAMBLE = "[Scheduled task — RETRY. This is an unattended run. The previous attempt "
    . "could not reach the home agent. Try the instruction below once more, doing only what it asks. "
    . "If the home agent is still unreachable, call notify_me ONCE saying what could not be done "
    . "and that it will not be tried again.]";

$tasks     = new ScheduledTasks();
$notifier  = Notifier::fromEnv();
$utc       = new DateTimeZone('UTC');

try {
    $users        = new Users();
    $oauth        = GoogleOAuth::fromEnv($users);
    $instructions = new UserInstructions();
    $memories     = new Memories();
    $workouts     = new Workouts();
    $conversations = new Conversations();
    $registry     = ToolRegistry::createStandard(
        $workouts,
        new Wishlist(),
        new Calendar($oauth),
        $instructions,
        $users,
        new Invites(),
        NativeMailer::fromEnv(),
        new Connections(),
        new Vinyls(),
        $memories,
        new ShoppingLists(),
        Dmi::fromEnv(),
        new WorkoutPlans(null, $workouts),
        new WorkEvents(),
        new WorkLog(),
        new ApiTokens(),
        new DevIdeas(),
        new Receipts(),
        new ReceiptStorage(),
        EmailService::fromEnv(),
        new CycleTracker(),
        new UserSettings(),
        new Income(),
        new OwnerDraws(),
        new BookkeepingAudit(),
        Discogs::fromEnv()
    );
    $gemini = GeminiClient::fromEnv();
    $loop   = new AssistantLoop(
        $gemini,
        $registry,
        $conversations,
        $instructions,
        $memories,
        new UserSettings(),
        wideTools: true,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'scheduled-tasks-cron: could not build the assistant: ' . $e->getMessage() . "\n");
    exit(0);
}

$routines = new Routines();

/**
 * Runs one CLAIMED task — the same for a scheduled slot and for a requested routine run. A task with recorded exact steps replays
 * them straight at the home agent (about a second, the same every time, and it keeps what is needed to undo it); anything else, or
 * a replay that fails, goes through the assistant with the stored instruction.
 *
 * @param array<string, mixed> $task id, user_id, conversation_id, instruction, notify_always, steps
 */
$execute = static function (array $task) use ($registry, $notifier, $tasks, $conversations, $loop, $routines): void {
    $userId = (int) $task['user_id'];

    if (!empty($task['steps'])) {
        $call = static function (string $tool, array $args) use ($registry, $userId): array {
            try {
                return $registry->dispatch($tool, $args, $userId);
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        };
        $res = RoutineSteps::replay($task['steps'], $call);
        $routines->saveUndo($task['id'], $res['undo']);
        if ($res['ok']) {
            $title = $task['label'] . ' — done';
            $body  = 'Ran ' . $res['done'] . ' recorded step' . ($res['done'] === 1 ? '' : 's') . ($res['undo'] !== [] ? '. Say “undo that” to put it back.' : '.');
            $conversations->addMessage($task['conversation_id'], 'user', $task['instruction']);
            $registry->register(new NotifyMe($notifier, $tasks, $task['id'], $task['conversation_id']));
            $note = $registry->dispatch('notify_me', ['title' => $title, 'body' => $body], $userId);
            $conversations->addMessage($task['conversation_id'], 'tool', (string) json_encode($note, JSON_UNESCAPED_UNICODE), 'notify_me');
            $conversations->addMessage($task['conversation_id'], 'assistant', $body);
            $tasks->recordRun($task['id'], true, $body);

            return;
        }
        // Not the home agent being away (the model run below has its own retry for that): the recording is stale
        // (an id that is gone, a refused path) — forget it, so the run below records fresh steps.
        if (!RoutineSteps::unreachable((string) $res['error'])) {
            $routines->saveSteps($task['id'], null);
        }
    }

    $registry->register(new NotifyMe($notifier, $tasks, $task['id'], $task['conversation_id']));
    $seen = count($conversations->messages($task['conversation_id']));
    $report = $task['notify_always'] ? REPORT_ALWAYS : REPORT_QUIET;
    // The rules go to the model as context for this run only; the conversation keeps just the instruction,
    // so opening the run from a notification doesn't show the boilerplate as "your" message.
    $reply = $loop->handle($task['user_id'], $task['conversation_id'], $task['instruction'], null, null, null, UNATTENDED_PREAMBLE . $report);

    // The home agent (the Pico) not answering is usually a blip: wait, then try once more.
    $fresh = array_slice($conversations->messages($task['conversation_id']), $seen);
    if (ToolMemory::homeUnreachable($fresh)) {
        sleep(RETRY_AFTER_SECONDS);
        $reply = $loop->handle($task['user_id'], $task['conversation_id'], $task['instruction'], null, null, null, RETRY_PREAMBLE);
    }
    // A run that was only home-device writes is kept as the routine's exact steps, so the next one is a replay.
    if (empty($task['steps']) && !RoutineSteps::conditional((string) $task['instruction'])) {
        $steps = RoutineSteps::record($loop->lastCalls());
        if ($steps !== null) {
            $routines->saveSteps($task['id'], $steps);
        }
    }
    // What the run came to, for the scheduled-tasks card (its closing words; a notify_me has its own title).
    $tasks->recordRun($task['id'], true, (string) $reply);
};

// ---- Routine runs that were REQUESTED (a phrase, the card's Run now, an approved question, a place trigger) ----
$ranRequested = 0;
foreach ($routines->requested() as $r) {
    try {
        // The user asked for it (said it, tapped Run now, said yes): conditions and "ask first" do not apply.
        // Automatic triggers (a place, the clock) go through the conditions, and through the question when set to ask.
        $trigger = (string) $r['run_trigger'];
        $byUser  = str_starts_with($trigger, 'you said:') || str_starts_with($trigger, 'approved:') || str_starts_with($trigger, 'run now');
        if (!$byUser) {
            $c   = $r['conditions'];
            $ctx = [];
            if (!empty($c['away'])) {
                $ctx['at_home'] = HomeNow::check(new LocationPoints(), new Places(), $r['user_id']);
            }
            if (!empty($c['others_away'])) {
                $ctx['others_home'] = Household::othersHome(new \App\Data\Connections(), new LocationPoints(), new Places(), $r['user_id']);
            }
            $placeIds = array_values(array_unique(array_map('intval', array_merge((array) ($c['in_places'] ?? []), (array) ($c['out_places'] ?? [])))));
            if ($placeIds !== []) {
                $ctx['inside'] = PlaceNow::check(new LocationPoints(), new Places(), $r['user_id'], $placeIds);
            }
            $why = Routines::gate($r, $ctx);
            if ($why !== null) {
                $routines->clearRequest($r['id']);
                $tasks->recordRun($r['id'], true, 'Skipped (' . $trigger . ') — ' . $why . '.');
                // past its `until`: it is over, so it stops showing as live and stops listening
                if (RoutineGate::expired($r['conditions'], Routines::nowLocal())) {
                    $routines->finish($r['id']);
                }
                continue;
            }
            if ($r['run_mode'] === 'ask') {
                $routines->clearRequest($r['id']);
                $askId = $routines->createRequest($r['id'], $r['user_id'], $trigger);
                $notifier->notify($r['user_id'], NotificationTypes::ROUTINE_ASK, 'Run “' . $r['label'] . '”?', ucfirst($trigger) . ' — tap to run it or skip it.', '/?ask=' . $askId);
                continue;
            }
        }
        if (!$routines->claimRequest($r['id'])) {
            continue;   // another process (the background run or the minute cron) got it
        }
        $execute($r);
        $ranRequested++;
        if (!empty($r['conditions']['once'])) {
            $routines->finish($r['id']);   // a one-off has done its one run
        }
    } catch (\Throwable $e) {
        error_log('scheduled-tasks-cron routine ' . $r['id'] . ': ' . $e->getMessage());
        try {
            $tasks->recordRun($r['id'], false, $e->getMessage());
        } catch (\Throwable $ignored) {
            // bookkeeping only
        }
    }
}
if ($ranRequested > 0) {
    fwrite(STDERR, 'scheduled-tasks-cron: ran ' . $ranRequested . " requested routine(s).\n");
}
if ($requestedOnly) {
    exit(0);
}
try {
    $routines->finishExpired();   // routines past their `until` are over
} catch (\Throwable $e) {
    error_log('scheduled-tasks-cron finishExpired: ' . $e->getMessage());
}

$due = $tasks->due();
$ran = 0;

foreach ($due as $task) {
    try {
        // Claim (and reschedule) FIRST — only the run that wins this advances next_run_at,
        // so an overlapping cron run skips it, and this task's slot moves on regardless
        // of whether the run below succeeds. A one-off is finished by its claim.
        if ($task['frequency'] === 'once') {
            if (!$tasks->claimOnce($task['id'])) {
                continue;
            }
        } else {
            $args = [$task['frequency'], $task['time_of_day'], $task['day_of_week']];
            $next = ScheduledTasks::nextRunAtUtc(...$args, after: new DateTimeImmutable($task['next_run_at'], $utc),
                intervalMinutes: $task['interval_minutes']);
            // Cron was down for a while: skip the missed slots instead of replaying them one a minute.
            if ($next <= new DateTimeImmutable('now', $utc)) {
                $next = ScheduledTasks::nextRunAtUtc(...$args, intervalMinutes: $task['interval_minutes']);
            }
            if (!$tasks->claimAndReschedule($task['id'], $next->format('Y-m-d H:i:s'))) {
                continue;
            }
        }

        // "Ask first": the slot is used up (it moves on as usual), the question waits on the card and in a push, and only a yes runs it.
        if (($task['run_mode'] ?? 'auto') === 'ask' && $task['frequency'] !== 'once') {
            $askId = $routines->createRequest($task['id'], $task['user_id'], 'scheduled time');
            $notifier->notify($task['user_id'], NotificationTypes::ROUTINE_ASK, 'Run “' . $task['label'] . '”?', 'It is time — tap to run it or skip it.', '/?ask=' . $askId);
            continue;
        }

        $execute($task);
        $ran++;
    } catch (\Throwable $e) {
        error_log('scheduled-tasks-cron task ' . $task['id'] . ': ' . $e->getMessage());
        try {
            $tasks->recordRun($task['id'], false, $e->getMessage());
        } catch (\Throwable $ignored) {
            // bookkeeping only
        }
    }
}

if ($due !== []) {
    fwrite(STDERR, 'scheduled-tasks-cron: ran ' . $ran . ' of ' . count($due) . " due.\n");
}
