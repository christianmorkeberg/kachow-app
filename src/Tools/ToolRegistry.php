<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ApiTokens;
use App\Data\Calendar;
use App\Data\Connections;
use App\Data\AppFlags;
use App\Data\CycleTracker;
use App\Data\DevIdeas;
use App\Data\ExerciseAliases;
use App\Data\FeedbackReports;
use App\Data\Invites;
use App\Data\Memories;
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
use App\Mail\Mailer;
use App\Music\Discogs;
use App\Receipts\ReceiptStorage;
use App\Weather\Dmi;
use InvalidArgumentException;

/**
 * Collects the available tools, exposes their declarations to Gemini, and
 * dispatches functionCalls by name.
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * Builds a registry with the standard v1 tool set wired to the data layer.
     */
    public static function createStandard(
        Workouts $workouts,
        Wishlist $wishlist,
        Calendar $calendar,
        UserInstructions $instructions,
        Users $users,
        Invites $invites,
        Mailer $mailer,
        Connections $connections,
        Vinyls $vinyls,
        Memories $memories,
        ShoppingLists $shoppingLists,
        Dmi $weather,
        WorkoutPlans $workoutPlans,
        WorkEvents $workEvents,
        WorkLog $workLog,
        ApiTokens $apiTokens,
        DevIdeas $devIdeas,
        Receipts $receipts,
        ReceiptStorage $receiptStorage,
        EmailService $email,
        CycleTracker $cycle,
        UserSettings $userSettings,
        ?Discogs $discogs = null
    ): self {
        $registry = new self();
        $exerciseAliases = new ExerciseAliases();
        $registry->register(new LogWorkout($workouts, $exerciseAliases));
        $registry->register(new GetWorkoutHistory($workouts, $exerciseAliases));
        $registry->register(new GetWorkoutProgress($workouts, $exerciseAliases));
        $registry->register(new MergeExercises($workouts, $exerciseAliases));
        $registry->register(new UpdateWorkout($workouts));
        $registry->register(new DeleteWorkout($workouts));
        $registry->register(new CreateWorkoutPlan($workoutPlans));
        $registry->register(new GetWorkoutPlan($workoutPlans));
        $registry->register(new GetWeekPlan($workoutPlans));
        $registry->register(new CheckOffExercise($workoutPlans));
        $registry->register(new UncheckExercise($workoutPlans));
        $registry->register(new AddPlanExercise($workoutPlans));
        $registry->register(new RemovePlanExercise($workoutPlans));
        $registry->register(new DeleteWorkoutPlan($workoutPlans));
        $registry->register(new AddWishlistItem($wishlist));
        $registry->register(new GetWishlist($wishlist));
        $registry->register(new UpdateWishlistItem($wishlist));
        $registry->register(new RemoveWishlistItem($wishlist));
        $registry->register(new GetCalendarEvents($calendar));
        $registry->register(new InsertCalendarEvent($calendar));
        $registry->register(new DeleteCalendarEvent($calendar));
        $registry->register(new ListCalendars($calendar));
        $registry->register(new SetMyName($users));
        $registry->register(new RememberAboutMe($memories));
        $registry->register(new GetAboutMe($memories));
        $registry->register(new UpdateAboutMe($memories));
        $registry->register(new ForgetAboutMe($memories));
        $registry->register(new RememberInstruction($instructions));
        $registry->register(new GetInstructions($instructions));
        $registry->register(new ForgetInstruction($instructions));
        $registry->register(new CreateInvite($users, $invites, $mailer));
        $registry->register(new SendConnectionRequest($connections, $users, $mailer));
        $registry->register(new ListConnections($connections));
        $registry->register(new AcceptConnectionRequest($connections, $users, $mailer));
        $registry->register(new RemoveConnection($connections));
        $registry->register(new UpdateConnectionSharing($connections));
        $registry->register(new ListShoppingLists($connections, $shoppingLists));
        $registry->register(new GetShoppingList($connections, $shoppingLists));
        $registry->register(new AddToShoppingList($connections, $shoppingLists));
        $registry->register(new CheckOffItem($connections, $shoppingLists));
        $registry->register(new UncheckItem($connections, $shoppingLists));
        $registry->register(new RemoveFromShoppingList($connections, $shoppingLists));
        $registry->register(new ClearCheckedItems($connections, $shoppingLists));
        $registry->register(new DeleteShoppingList($connections, $shoppingLists));
        $registry->register(new GetCurrentWeather($weather));
        $registry->register(new GetWeatherForecast($weather));
        $registry->register(new GetWorkHours($workEvents));
        $registry->register(new GetWorkSummary($workEvents));
        $registry->register(new LogWorkEvent($workEvents));
        $registry->register(new DeleteWorkEvent($workEvents));
        $registry->register(new GetWorkTrackingSetup($apiTokens));
        $registry->register(new LogWorkTime($workLog, $calendar, $userSettings));
        $registry->register(new GetWorkLog($workLog));
        $registry->register(new ExportWorkLog($workLog));
        $registry->register(new NoteDevIdea($devIdeas));
        $registry->register(new ListDevIdeas($devIdeas));
        $registry->register(new RemoveDevIdea($devIdeas));
        $registry->register(new AddExpense($receipts));
        $registry->register(new UpdateReceipt($receipts));
        $registry->register(new DeleteReceipt($receipts, $receiptStorage));
        $registry->register(new GetExpenses($receipts));
        $registry->register(new ExportExpensesCsv($receipts));
        $registry->register(new GetEmails($email));
        $registry->register(new ReadEmail($email));
        $registry->register(new DraftEmail($email));
        $registry->register(new SendEmail($email));
        $registry->register(new ListEmailAccounts($email));
        $registry->register(new LogPeriod($cycle));
        $registry->register(new GetCycleStatus($cycle));
        $registry->register(new RemovePeriod($cycle));
        $registry->register(new LogCycleDay($cycle));
        $registry->register(new GetConnectedWorkouts($connections, $workouts));
        $registry->register(new GetConnectedWishlist($connections, $wishlist));
        $registry->register(new GetConnectedCalendar($connections, $calendar));
        $registry->register(new GetConnectedCycle($connections, $cycle));
        $registry->register(new GetSettings($userSettings));
        $registry->register(new UpdateSetting($userSettings));
        $registry->register(new AddVinyl($vinyls, $discogs));
        $registry->register(new GetVinyls($vinyls));
        $registry->register(new RateVinyl($vinyls));
        $registry->register(new UpdateVinyl($vinyls));
        $registry->register(new RemoveVinyl($vinyls));
        $registry->register(new RecommendVinyl($vinyls));
        $registry->register(new AssessVinyl($vinyls, $discogs));
        $feedback = new FeedbackReports();
        $registry->register(new ListFeedback($users, $feedback));
        $registry->register(new ResolveFeedback($users, $feedback));
        $registry->register(new SetDiagnostics($users, new AppFlags()));

        return $registry;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<int, Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Function declarations for the Gemini request: one entry per tool with
     * name, description and parameters schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function declarations(): array
    {
        $declarations = [];
        foreach ($this->tools as $tool) {
            $params = $tool->parameters();
            // Gemini requires parameters.properties to be a JSON object ({}), but a
            // no-arg tool's empty PHP array encodes as a JSON list ([]), which Gemini
            // rejects ("Cannot bind a list to map for field 'properties'"). Force an
            // object for parameter-less tools.
            if (($params['properties'] ?? null) === []) {
                $params['properties'] = new \stdClass();
            }
            $declarations[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $params,
            ];
        }

        return $declarations;
    }

    /**
     * Executes a tool by name and returns its result.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function dispatch(string $name, array $arguments, int $userId): array
    {
        $tool = $this->get($name);
        if ($tool === null) {
            throw new InvalidArgumentException("Unknown tool: {$name}");
        }

        return $tool->execute($arguments, $userId);
    }
}
