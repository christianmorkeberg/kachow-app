<?php

declare(strict_types=1);

/**
 * Scheduled-task runner — run every 30 minutes by cron.
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

// Crontab (kept out of the block comment above so the "* / 30" doesn't close it):
//   */30 * * * * php /home/kachowdk/assistant-app/bin/scheduled-tasks-cron.php >/dev/null 2>&1

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
use App\Tools\NotifyMe;
use App\Tools\ToolRegistry;
use App\Weather\Dmi;

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
    . "other action on your own initiative. Call notify_me only if there's something "
    . "worth telling the user; otherwise finish quietly without calling it.]\n\n";

$tasks     = new ScheduledTasks();
$notifier  = Notifier::fromEnv();
$utc       = new DateTimeZone('UTC');

try {
    $users        = new Users();
    $oauth        = GoogleOAuth::fromEnv($users);
    $instructions = new UserInstructions();
    $memories     = new Memories();
    $workouts     = new Workouts();
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
        new Conversations(),
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
        $currentNext = new DateTimeImmutable($task['next_run_at'], $utc);
        $next = ScheduledTasks::nextRunAtUtc(
            $task['frequency'],
            $task['time_of_day'],
            $task['day_of_week'],
            $currentNext,
        );

        // Claim (and reschedule) FIRST — only the run that wins this advances next_run_at,
        // so an overlapping cron run skips it, and this task's slot moves on regardless
        // of whether the run below succeeds.
        if (!$tasks->claimAndReschedule($task['id'], $next->format('Y-m-d H:i:s'))) {
            continue;
        }

        $registry->register(new NotifyMe($notifier, $task['conversation_id']));
        $loop->handle($task['user_id'], $task['conversation_id'], UNATTENDED_PREAMBLE . $task['instruction']);
        $ran++;
    } catch (\Throwable $e) {
        error_log('scheduled-tasks-cron task ' . $task['id'] . ': ' . $e->getMessage());
    }
}

if ($due !== []) {
    fwrite(STDERR, 'scheduled-tasks-cron: ran ' . $ran . ' of ' . count($due) . " due.\n");
}
