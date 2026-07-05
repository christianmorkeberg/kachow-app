<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;
use App\Data\Connections;
use App\Data\Invites;
use App\Data\Memories;
use App\Data\ShoppingLists;
use App\Data\UserInstructions;
use App\Data\Users;
use App\Data\Vinyls;
use App\Data\Wishlist;
use App\Data\Workouts;
use App\Mail\Mailer;
use App\Music\Discogs;
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
        ?Discogs $discogs = null
    ): self {
        $registry = new self();
        $registry->register(new LogWorkout($workouts));
        $registry->register(new GetWorkoutHistory($workouts));
        $registry->register(new UpdateWorkout($workouts));
        $registry->register(new DeleteWorkout($workouts));
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
        $registry->register(new GetConnectedWorkouts($connections, $workouts));
        $registry->register(new GetConnectedWishlist($connections, $wishlist));
        $registry->register(new GetConnectedCalendar($connections, $calendar));
        $registry->register(new AddVinyl($vinyls, $discogs));
        $registry->register(new GetVinyls($vinyls));
        $registry->register(new RateVinyl($vinyls));
        $registry->register(new UpdateVinyl($vinyls));
        $registry->register(new RemoveVinyl($vinyls));
        $registry->register(new RecommendVinyl($vinyls));
        $registry->register(new AssessVinyl($vinyls, $discogs));

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
            $declarations[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'parameters'  => $tool->parameters(),
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
