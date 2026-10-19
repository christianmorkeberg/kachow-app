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
use App\Notify\Notifier;
use App\Notify\WebPush;
use App\Receipts\ReceiptStorage;
use App\Data\SystemTasks;
use App\Support\SystemTaskDispatcher;
use App\Support\SystemTaskRunner;
use App\Support\ToolMemory;
use App\Tools\NotifyMe;
use App\Tools\ToolRegistry;
use App\Weather\Dmi;

// The built-in cron jobs the dispatcher owns (see App\Data\SystemTasks) run when this script ends — after the
// user's tasks, so a long job never delays them, and also when it exits early below (no push configured).
// Jobs left on the cPanel entry are untouched: with none switched over this is one cheap query.
register_shutdown_function(static function (): void {
    try {
        SystemTaskDispatcher::runDue(new SystemTasks(), [SystemTaskRunner::class, 'run'], __DIR__);
    } catch (\Throwable $e) {
        error_log('scheduled-tasks-cron system tasks: ' . $e->getMessage());
    }
});

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
        // What the run came to, for the scheduled-tasks card (its closing words; a notify_me has its own title).
        $tasks->recordRun($task['id'], true, (string) $reply);
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
