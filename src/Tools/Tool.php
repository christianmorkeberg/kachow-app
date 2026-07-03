<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * A single capability exposed to Gemini as a callable function.
 *
 * Implementations MUST stay thin: translate the model's arguments into a call
 * into the Data/ layer and shape the result. No query or business logic here
 * (spec §3 critical rule) — that lives in Data/.
 *
 * $userId is supplied by the assistant loop, never by the model — the model must
 * not be able to choose which user's data it touches.
 */
interface Tool
{
    /** Machine name Gemini calls (snake_case, stable). */
    public function name(): string;

    /** Clear, specific description — Gemini's tool selection accuracy depends on it. */
    public function description(): string;

    /**
     * JSON-schema (OpenAPI-like) object describing the arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * Executes the tool for a given user and returns a JSON-serializable result
     * to hand back to the model.
     *
     * @param array<string, mixed> $arguments decoded functionCall args from Gemini
     * @return array<string, mixed>
     */
    public function execute(array $arguments, int $userId): array;
}
