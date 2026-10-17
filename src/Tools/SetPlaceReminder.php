<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\LocationPoints;
use App\Data\Places;
use App\Data\PushSubscriptions;
use App\Data\Reminders;
use App\Support\PlaceArrival;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: a reminder that fires the next time location tracking sees the user arrive at a saved
 * place, or at any place of a type ("next time I'm at work"). Delivered as a push by
 * Notify\PlaceReminderRunner as positions come in.
 */
final class SetPlaceReminder implements Tool
{
    /** "Here right now" = a position this recent inside the place. */
    private const HERE_WITHIN_S = 1800;

    public function __construct(
        private Reminders $reminders,
        private Places $places,
        private LocationPoints $points,
        private PushSubscriptions $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'set_place_reminder';
    }

    public function description(): string
    {
        return 'Sets a reminder that fires the NEXT TIME the user arrives at a place, using location tracking — '
            . '"remind me to ask about the invoice next time I\'m at DSB", "next time I\'m at Netto, remind me to buy '
            . 'batteries", "remind me at work tomorrow-ish to…" (no exact time), Danish "mind mig om det næste gang jeg '
            . 'er på arbejde". Give `place` = the name of one of their SAVED places, or `place_type` (work, home, '
            . 'business, commute, private, other) for "at work" / "at home" = any place of that type. Only saved '
            . 'places work: if the place is unknown, say so and offer to save it (save_place). For a reminder at a '
            . 'TIME use set_reminder instead. It is listed and cancelled like other reminders.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text'       => ['type' => 'string', 'description' => 'What to remind them about, as they will see it.'],
                'place'      => ['type' => 'string', 'description' => 'Name of a saved place (e.g. as in list_places).'],
                'place_type' => ['type' => 'string', 'enum' => Places::TYPES, 'description' => 'Any place of this type, e.g. "work" for "at work".'],
            ],
            'required'   => ['text'],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $text = trim((string) ($arguments['text'] ?? ''));
        if ($text === '') {
            return ['error' => 'What should I remind you about?'];
        }
        $name = trim((string) ($arguments['place'] ?? ''));
        $type = trim((string) ($arguments['place_type'] ?? ''));
        $all  = $this->places->list($userId);
        if ($all === []) {
            return ['error' => 'There are no saved places yet. Offer to save this place first (save_place), then set the reminder.'];
        }

        $placeId = null;
        $placeType = null;
        if ($name !== '') {
            $place = $this->findPlace($all, $name);
            if ($place === null) {
                return ['error' => 'No saved place is called "' . $name . '". Saved places: '
                    . implode(', ', array_column($all, 'name')) . '. Ask which one, or offer to save it (save_place).'];
            }
            $placeId = (int) $place['id'];
            $label   = $place['name'];
            $targets = [$place];
        } elseif (in_array($type, Places::TYPES, true)) {
            $targets = array_values(array_filter($all, static fn (array $p): bool => $p['type'] === $type));
            if ($targets === []) {
                return ['error' => 'No saved place has the type "' . $type . '". Mark the right place with that type '
                    . '(update_place) or name the place instead.'];
            }
            $placeType = $type;
            $label     = 'any ' . $type . ' place';
        } else {
            return ['error' => 'Which place? Give a saved place name, or a place type like "work".'];
        }

        $latest = $this->points->latest($userId);
        if ($latest === null) {
            return ['error' => 'Location tracking has never sent a position, so a place reminder could never fire. '
                . 'Offer get_location_tracking_setup.'];
        }
        $age  = time() - (new DateTimeImmutable($latest['recorded_at'], new DateTimeZone('UTC')))->getTimestamp();
        $here = $age <= self::HERE_WITHIN_S
            && PlaceArrival::insideAny($targets, $latest['lat'], $latest['lon'], $latest['acc']);

        $id = $this->reminders->createAtPlace($userId, $placeId, $placeType, $label, $text, !$here);

        $notes = [];
        if ($here) {
            $notes[] = 'They are there right now, so it fires on their NEXT arrival (after leaving) — say so.';
        }
        if ($age > 6 * 3600) {
            $notes[] = 'Location tracking has been quiet for hours; it only fires once positions arrive again — mention it.';
        }
        if (!$this->subscriptions->hasAny($userId)) {
            $notes[] = 'The user has NO notification device enabled — tell them to turn on notifications or they will not get it.';
        }

        return [
            'scheduled' => true,
            'id'        => $id,
            'text'      => $text,
            'where'     => $label,
            'armed_now' => !$here,
            'note'      => 'Confirm briefly: it fires when they arrive (within a few minutes, from location tracking). '
                . implode(' ', $notes),
        ];
    }

    /** Exact name (any case), else the single place whose name contains it or is contained in it. */
    private function findPlace(array $all, string $name): ?array
    {
        $key = mb_strtolower($name);
        $loose = [];
        foreach ($all as $p) {
            $pn = mb_strtolower($p['name']);
            if ($pn === $key) {
                return $p;
            }
            if (mb_strlen($key) >= 3 && (str_contains($pn, $key) || str_contains($key, $pn))) {
                $loose[] = $p;
            }
        }

        return count($loose) === 1 ? $loose[0] : null;
    }
}
