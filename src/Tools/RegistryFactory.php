<?php

declare(strict_types=1);

namespace App\Tools;

use App\Auth\GoogleOAuth;
use App\Data\ApiTokens;
use App\Data\BookkeepingAudit;
use App\Data\Calendar;
use App\Data\Connections;
use App\Data\CycleTracker;
use App\Data\DevIdeas;
use App\Data\Income;
use App\Data\Invites;
use App\Data\Memories;
use App\Data\OwnerDraws;
use App\Data\Receipts;
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
use App\Receipts\ReceiptStorage;
use App\Weather\Dmi;

/**
 * The same tool/dependency graph api/chat.php and the scheduled-tasks cron build, in one place for the
 * voice endpoints (which run single tools outside a chat turn). chat.php and the cron keep their own copies.
 */
final class RegistryFactory
{
    public static function standard(Users $users, Memories $memories, UserInstructions $instructions): ToolRegistry
    {
        $workouts = new Workouts();

        return ToolRegistry::createStandard(
            $workouts,
            new Wishlist(),
            new Calendar(GoogleOAuth::fromEnv($users)),
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
    }
}
