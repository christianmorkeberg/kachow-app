<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\AgentCommands;
use App\Data\HomeDevices;
use App\Data\ApiTokens;
use App\Data\BookkeepingAudit;
use App\Data\Books;
use App\Data\Calendar;
use App\Data\Connections;
use App\Data\Conversations;
use App\Data\AppFlags;
use App\Data\CycleTracker;
use App\Data\DevIdeas;
use App\Data\Cash;
use App\Data\CashEntries;
use App\Data\Income;
use App\Data\Mileage;
use App\Data\MileageSuggestions;
use App\Data\Moms;
use App\Data\OwnerDraws;
use App\Data\ProfitLoss;
use App\Data\ExerciseAliases;
use App\Data\FeedbackReports;
use App\Data\Invites;
use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\Timeline;
use App\Data\Memories;
use App\Data\PushSubscriptions;
use App\Data\Receipts;
use App\Data\Reminders;
use App\Data\Routines;
use App\Data\ScheduledTasks;
use App\Data\SystemTasks;
use App\Data\VoiceLog;
use App\Data\ShoppingLists;
use App\Data\UserInstructions;
use App\Data\Users;
use App\Data\UserSettings;
use App\Data\Vinyls;
use App\Data\Wishlist;
use App\Data\WorkClockShadow;
use App\Data\WorkEvents;
use App\Data\WorkLog;
use App\Data\TrainingPrograms;
use App\Data\WorkoutPlans;
use App\Support\ProgramPlanner;
use App\Data\Workouts;
use App\Email\EmailService;
use App\Mail\Mailer;
use App\Data\WebSnapshots;
use App\Home\HomeOwner;
use App\Web\PageFetcher;
use App\Home\HueSync;
use App\Maps\MapDistance;
use App\Maps\PoiNames;
use App\Music\Discogs;
use App\Receipts\ReceiptStorage;
use App\Energy\EnergiDataService;
use App\Energy\PowerPrices;
use App\Weather\Dmi;
use App\Weather\OpenMeteo;
use App\Weather\WeatherService;
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
        Income $income,
        OwnerDraws $ownerDraws,
        BookkeepingAudit $bookAudit,
        ?Discogs $discogs = null
    ): self {
        $registry = new self();
        $exerciseAliases = new ExerciseAliases();
        $registry->register(new LogWorkout($workouts, $exerciseAliases));
        $registry->register(new GetWorkoutHistory($workouts, $exerciseAliases));
        $registry->register(new GetWorkoutProgress($workouts, $exerciseAliases));
        $registry->register(new MergeExercises($workouts, $exerciseAliases));
        $registry->register(new TrainingLoad($workouts, $exerciseAliases, $connections));
        $registry->register(new UpdateWorkout($workouts));
        $registry->register(new DeleteWorkout($workouts));
        $registry->register(new CreateWorkoutPlan($workoutPlans));
        $programs = new TrainingPrograms();
        $planner  = new ProgramPlanner($programs, $workoutPlans);
        $registry->register(new GetWorkoutPlan($workoutPlans, $planner));
        $registry->register(new GetWeekPlan($workoutPlans, $planner));
        // Training programmes: a multi-week plan as data; a programme day becomes the day's plan when asked for (ProgramPlanner).
        $registry->register(new SaveTrainingProgram($programs));
        $registry->register(new AddProgramDays($programs));
        $registry->register(new GetTrainingProgram($programs));
        $registry->register(new UpdateTrainingProgram($programs));
        $registry->register(new RemoveProgramDays($programs));
        $registry->register(new DeleteTrainingProgram($programs));
        $registry->register(new FindProgramInMemory($memories, $instructions, $programs));
        $registry->register(new CheckOffExercise($workoutPlans, $planner));
        $registry->register(new UncheckExercise($workoutPlans));
        $registry->register(new AddPlanExercise($workoutPlans));
        $registry->register(new RemovePlanExercise($workoutPlans));
        $registry->register(new DeleteWorkoutPlan($workoutPlans));
        $registry->register(new AddWishlistItem($wishlist));
        $registry->register(new GetWishlist($wishlist));
        $registry->register(new UpdateWishlistItem($wishlist));
        $registry->register(new RemoveWishlistItem($wishlist));
        $registry->register(new GetCalendarEvents($calendar));
        $registry->register(new GetCalendarMonth($calendar));
        $registry->register(new InsertCalendarEvent($calendar));
        $registry->register(new UpdateCalendarEvent($calendar));
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
        $registry->register(new SetListGrouping($connections, $shoppingLists));
        // DMI with automatic Open-Meteo failover (rate limits / non-DK locations).
        $weatherService = new WeatherService($weather, new OpenMeteo());
        $registry->register(new GetCurrentWeather($weatherService));
        $registry->register(new GetWeatherForecast($weatherService));
        $registry->register(new GetPowerPrices(new PowerPrices(new EnergiDataService())));
        $registry->register(new GetWorkHours($workEvents));
        $registry->register(new GetWorkSummary($workEvents));
        $registry->register(new ShowChart());
        $registry->register(new LogWorkEvent($workEvents, static fn (int $u): bool => \App\Support\HomeNow::check(new LocationPoints(), new Places(), $u)));
        $registry->register(new DeleteWorkEvent($workEvents));
        $registry->register(new GetWorkTrackingSetup($apiTokens));
        $homeOwner = new HomeOwner($apiTokens, $connections);   // whose Pico a user talks to (own, or a shared one)
        $registry->register(new GetHomeAgentSetup($apiTokens, $homeOwner));
        $agentCommands = new AgentCommands();
        $hueSync       = new HueSync(new HomeDevices(), HueSync::viaAgent($agentCommands));
        $homeCall = new HomeDeviceCall($agentCommands, $apiTokens, $hueSync, $homeOwner);
        $registry->register($homeCall);
        $registry->register(new HueColorTool());
        $registry->register(new ShowHome());
        $registry->register(new SetThermostat($homeCall, $userSettings));
        $registry->register(new GetThermostats($homeCall, $userSettings));
        $registry->register(new GetThermostatProbe(new \App\Data\ThermostatSamples()));
        $registry->register(new RefreshHomeDevices($hueSync, $apiTokens, $homeOwner));
        $locationPoints = new LocationPoints();
        $places         = new Places();
        $timeline       = new Timeline($locationPoints, $places, $userSettings);
        $registry->register(new GetLocationDay($timeline));
        $registry->register(new GetStays($timeline));
        $registry->register(new GetTrips($timeline));
        $registry->register(new SuggestPlaces($timeline, $places, $locationPoints, new PoiNames()));
        $registry->register(new GetWorkClock(new WorkClockShadow($timeline, $workEvents, $userSettings)));
        $registry->register(new GetLocationTrackingSetup($apiTokens, $locationPoints));
        $registry->register(new LogWorkTime($workLog, $calendar, $userSettings));
        $registry->register(new GetWorkLog($workLog));
        $registry->register(new ExportWorkLog($workLog));
        $registry->register(new NoteDevIdea($devIdeas, $users));
        $registry->register(new ListDevIdeas($devIdeas, $users));
        $registry->register(new SetDevIdeaStatus($devIdeas, $users));
        $registry->register(new UpdateDevIdea($devIdeas, $users));
        $registry->register(new RemoveDevIdea($devIdeas, $users));
        $registry->register(new AddExpense($receipts));
        $registry->register(new UpdateReceipt($receipts));
        $registry->register(new DeleteReceipt($receipts, $receiptStorage));
        $registry->register(new GetExpenses($receipts));
        $registry->register(new ExportExpensesCsv($receipts));
        $registry->register(new MarkExpenseReimbursed($receipts, $bookAudit));
        $registry->register(new AddIncome($income, $bookAudit));
        $registry->register(new UpdateIncome($income, $bookAudit));
        $registry->register(new GetIncome($income));
        $registry->register(new MarkInvoicePaid($income, $bookAudit));
        $registry->register(new AddOwnerDraw($ownerDraws, $bookAudit));
        $registry->register(new GetOwnerDraws($ownerDraws));
        $mileage     = new Mileage($userSettings);
        $mapDistance = new MapDistance();
        $registry->register(new SavePlace($places, $locationPoints, $mapDistance));
        $registry->register(new ListPlaces($places, $locationPoints));
        $registry->register(new UpdatePlace($places, $locationPoints, $mapDistance));
        $registry->register(new DeletePlace($places));
        $registry->register(new GetBooks(new Books($income, $receipts, $ownerDraws, $userSettings, $mileage)));
        $registry->register(new GetMoms(new Moms($income, $receipts)));
        $cashEntries = new CashEntries();
        $cash        = new Cash($income, $receipts, $ownerDraws, $cashEntries, $userSettings, $mileage);
        $registry->register(new GetCash($cash));
        $registry->register(new RecordCashMovement($cashEntries, $cash));
        $registry->register(new GetProfitLoss(new ProfitLoss($income, $receipts, $userSettings, $mileage)));
        $mileageSuggest = new MileageSuggestions($timeline, $places, $mileage, $mapDistance);
        $registry->register(new LogTrip($mileage, $mapDistance, $userSettings));
        $registry->register(new GetMileage($mileage, $mileageSuggest));
        $registry->register(new UpdateTrip($mileage));
        $registry->register(new DeleteTrip($mileage));
        $registry->register(new GetDrivingDistance($mapDistance, $userSettings));
        $registry->register(new CreateInvoice($income, $userSettings, $bookAudit));
        $registry->register(new SetCompanyProfile($userSettings));
        $registry->register(new GetEmails($email));
        $registry->register(new ReadEmail($email));
        $registry->register(new AttachEmailFile($email, $receipts, $income, $receiptStorage));
        $registry->register(new MarkEmailsRead($email));
        $export = new ExportData($users); // registered (and given the registry) at the end
        $registry->register(new DraftEmail($email, $export));
        $registry->register(new SendEmail($email, $export));
        $registry->register(new ListEmailAccounts($email));
        $registry->register(new LogPeriod($cycle));
        $registry->register(new GetCycleStatus($cycle));
        $registry->register(new RemovePeriod($cycle));
        $registry->register(new LogCycleDay($cycle));
        $registry->register(new GetConnectedWorkouts($connections, $workouts));
        $registry->register(new GetConnectedWorkoutProgress($connections, $workouts, $exerciseAliases));
        $registry->register(new GetConnectedWorkoutPlan($connections, $workoutPlans));
        $registry->register(new GetConnectedVinyls($connections, $vinyls));
        $registry->register(new GetConnectedWorkHours($connections, $workEvents));
        $registry->register(new LogConnectedWorkout($connections, $workouts, $exerciseAliases, $users));
        $registry->register(new GetConnectedWishlist($connections, $wishlist));
        $registry->register(new GetConnectedCalendar($connections, $calendar));
        $registry->register(new GetConnectedCycle($connections, $cycle));
        $registry->register(new GetSettings($userSettings));
        $registry->register(new UpdateSetting($userSettings));
        $registry->register(new GetAppearance($userSettings));
        $registry->register(new AddVinyl($vinyls, $discogs));
        $registry->register(new GetVinyls($vinyls));
        $registry->register(new RateVinyl($vinyls));
        $registry->register(new UpdateVinyl($vinyls));
        $registry->register(new RemoveVinyl($vinyls));
        $registry->register(new RecommendVinyl($vinyls));
        $registry->register(new AssessVinyl($vinyls, $discogs));
        $reminders = new Reminders();
        $registry->register(new SetReminder($reminders, new PushSubscriptions()));
        $registry->register(new SetPlaceReminder($reminders, $places, $locationPoints, new PushSubscriptions()));
        // Only handed to the model on a turn that carries a link or approved going online (AssistantLoop).
        $registry->register(new FetchWebpage(new PageFetcher(), new WebSnapshots()));
        $registry->register(new ListReminders($reminders));
        $registry->register(new CancelReminder($reminders));
        $scheduledTasks = new ScheduledTasks();
        $registry->register(new ScheduleTask($scheduledTasks, new Conversations(), new PushSubscriptions(), new Routines()));
        $systemTasks = new SystemTasks();
        $registry->register(new ListScheduledTasks($scheduledTasks, $systemTasks, $users));
        $registry->register(new SetScheduledTaskActive($scheduledTasks, $systemTasks, $users));
        $registry->register(new UpdateSystemTask($systemTasks, $scheduledTasks, $users));
        $registry->register(new CancelScheduledTask($scheduledTasks));
        // Routines: phrase / place triggered tasks on the same table (see Data\Routines).
        $routines = new Routines();
        $registry->register(new CreateRoutine($routines, $scheduledTasks, new Conversations(), new Places()));
        $registry->register(new UpdateRoutine($routines, $scheduledTasks, new Places()));
        $registry->register(new RunRoutine($routines));
        $registry->register(new SaveRegion(new Places(), $locationPoints, $mapDistance));
        $registry->register(new WhereAmI(new Places(), $locationPoints, $mapDistance));
        $registry->register(new GetSensorSetup($apiTokens));
        $registry->register(new UndoRoutine($routines, $homeCall));
        $feedback = new FeedbackReports();
        $registry->register(new ListFeedback($users, $feedback));
        $registry->register(new ResolveFeedback($users, $feedback));
        $registry->register(new SetDiagnostics($users, new AppFlags()));
        $registry->register(new GetVoiceLog(new VoiceLog(), $users));
        $registry->register($export);
        $export->setRegistry($registry);
        // Registered last: it reads the registry at call time to describe the full, current tool set.
        $registry->register(new ExplainCapabilities($registry));

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
