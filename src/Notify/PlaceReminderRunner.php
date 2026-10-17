<?php

declare(strict_types=1);

namespace App\Notify;

use App\Data\Places;
use App\Data\Reminders;
use App\Support\PlaceArrival;

/**
 * Delivers location-triggered reminders as positions arrive (called from api/owntracks.php
 * with the points it just stored). A user with no pending place reminders costs one query.
 * Each reminder is claimed atomically before the push, so a re-sent point never double-fires.
 */
final class PlaceReminderRunner
{
    /** @var (callable(int, string, string, string):void)|null */
    private $send;

    /**
     * @param (callable(int $userId, string $title, string $body, string $url):void)|null $send
     *        push sender; defaults to Notifier::fromEnv() (only built when something fires)
     */
    public function __construct(
        private Reminders $reminders,
        private Places $places,
        ?callable $send = null,
    ) {
        $this->send = $send;
    }

    /**
     * @param list<array{ts:int, lat:float, lon:float, acc:?int, vel:?int}> $points oldest first
     * @return list<int> ids of the reminders that fired
     */
    public function onPoints(int $userId, array $points): array
    {
        if ($points === []) {
            return [];
        }
        $pending = $this->reminders->pendingAtPlaces($userId);
        if ($pending === []) {
            return [];
        }
        $places = $this->places->list($userId);
        $fired  = [];

        foreach ($points as $pt) {
            $res = PlaceArrival::check($pending, $places, $pt);
            foreach ($res['arm'] as $id) {
                $this->reminders->arm($id);
            }
            foreach ($res['fire'] as $id) {
                if (!$this->reminders->claim($id)) {
                    continue;
                }
                $r = array_values(array_filter($pending, static fn (array $x): bool => $x['id'] === $id))[0];
                $this->push($userId, $r['place_label'] !== '' ? $r['place_label'] : 'Reminder', $r['text'], '/?card=reminder&rid=' . $id);
                $fired[] = $id;
            }
            // Later points in this batch see the updated state.
            $pending = array_values(array_filter(
                array_map(static fn (array $x): array => in_array($x['id'], $res['arm'], true) ? ['armed' => true] + $x : $x, $pending),
                static fn (array $x): bool => !in_array($x['id'], $fired, true)
            ));
            if ($pending === []) {
                break;
            }
        }

        return $fired;
    }

    private function push(int $userId, string $title, string $body, string $url): void
    {
        try {
            if ($this->send !== null) {
                ($this->send)($userId, $title, $body, $url);

                return;
            }
            if (WebPush::isConfigured()) {
                Notifier::fromEnv()->notify($userId, NotificationTypes::REMINDER, $title, $body, $url);
            }
        } catch (\Throwable $e) {
            error_log('place reminder ' . $url . ': ' . $e->getMessage());
        }
    }
}
