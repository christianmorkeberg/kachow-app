<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ExerciseAliases;
use App\Data\Workouts;

/**
 * Tool: merge several name variants of the SAME exercise into one canonical name.
 * Rewrites the user's existing logged sets to the canonical name AND remembers the
 * variants as aliases, so future logs (and the history/progression views) stay
 * consistent. This is the enforced version of "noting" a synonym — memory alone does
 * not re-label rows or bind future logs.
 */
final class MergeExercises implements Tool
{
    public function __construct(
        private Workouts $workouts,
        private ExerciseAliases $aliases,
    ) {
    }

    public function name(): string
    {
        return 'merge_exercises';
    }

    public function description(): string
    {
        return 'Merges two or more name variants of the SAME exercise into one canonical name for the '
            . 'user. Use whenever the user says exercise names mean the same thing — "squat = squats = '
            . 'backsquat", "dødløft = deadlift", "treat X and Y as the same lift", "standardise these". '
            . 'It rewrites existing logged sets to the canonical name AND remembers the variants so future '
            . 'logs are consistent (do NOT just note this in memory — call this tool). Pass every variant '
            . 'in "names" and the name to keep in "canonical". If the user has not said which name to '
            . 'keep, ask first.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'names' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'All the variant names to merge (e.g. ["Squats", "Backsquat"]). '
                        . 'Include every variant the user mentioned; the canonical name may also be listed.',
                ],
                'canonical' => [
                    'type'        => 'string',
                    'description' => 'The single name to standardise everything to, e.g. "Squat".',
                ],
            ],
            'required' => ['names', 'canonical'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $canonical = trim((string) ($arguments['canonical'] ?? ''));
        $rawNames  = $arguments['names'] ?? [];
        if ($canonical === '' || !is_array($rawNames)) {
            return ['error' => 'Provide the variant names and the canonical name to keep.'];
        }

        $names = [];
        foreach ($rawNames as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $names[] = $n;
            }
        }
        if ($names === []) {
            return ['error' => 'Provide at least one exercise name to merge.'];
        }

        // Remember each variant as an alias (self-referential ones are skipped).
        foreach ($names as $n) {
            $this->aliases->setAlias($userId, $n, $canonical);
        }

        // Re-label existing rows — include the canonical name so its own casing normalises.
        $rows = $this->workouts->renameExercises($userId, array_merge($names, [$canonical]), $canonical);

        return [
            'canonical'   => $canonical,
            'aliases_set' => count($names),
            'rows_updated' => $rows,
            'message'     => 'Merged to "' . $canonical . '"; future logs of the variants will use it too.',
        ];
    }
}
