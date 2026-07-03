<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\Calendar;
use App\Data\UserInstructions;
use App\Data\Wishlist;
use App\Data\Workouts;
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
        UserInstructions $instructions
    ): self {
        $registry = new self();
        $registry->register(new LogWorkout($workouts));
        $registry->register(new GetWorkoutHistory($workouts));
        $registry->register(new AddWishlistItem($wishlist));
        $registry->register(new GetWishlist($wishlist));
        $registry->register(new GetCalendarEvents($calendar));
        $registry->register(new InsertCalendarEvent($calendar));
        $registry->register(new RememberInstruction($instructions));
        $registry->register(new GetInstructions($instructions));
        $registry->register(new ForgetInstruction($instructions));

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
