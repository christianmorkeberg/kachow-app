<?php

declare(strict_types=1);

namespace App\Home;

/**
 * After "set the radiators to 16" ended as "sent" (the valves are asleep and apply it at their next wake-up), someone has to tell the user when the valves
 * have REPORTED the new target. The sent targets wait in PendingSets. Two things can settle them, and whichever comes first wins (the batch is handed to exactly
 * one of them): the gateway's own call when a valve reports (api/shelly-hook.php → GatewayReport), and THIS watcher, which runs after the reply
 * (App\Support\AfterReply) and polls only the valves still pending — a few seconds apart at first, then a minute apart — because the home agent handles one command at a time.
 *
 * Time, reading and delivery are injected: the unit test runs it with a fake clock and no sleeping.
 */
final class ThermostatConfirm
{
    /** Seconds to wait before each check; the last value repeats until the time is up. */
    private const DELAYS = [20, 30, 40, 60];

    /**
     * @param callable(int):?float            $targetOf the valve's reported target in °C, null when it could not be read
     * @param callable(string, ?int):void     $deliver  (text, conversation id): the push and the message in the chat
     * @param callable(int):void              $sleep    seconds
     * @param callable():int                  $now      unix seconds
     */
    public function __construct(private PendingSets $pending, private int $userId, private $targetOf, private $deliver, private $sleep, private $now)
    {
    }

    /** @return array{outcome: string, checks: int} outcome: confirmed | deadline | settled elsewhere */
    public function run(int $maxSeconds = 480): array
    {
        $start  = ($this->now)();
        $checks = 0;
        $step   = 0;
        while (true) {
            if ($this->pending->get($this->userId) === null) {
                return ['outcome' => 'settled elsewhere', 'checks' => $checks];     // the gateway told us first
            }
            $delay = self::DELAYS[min($step, count(self::DELAYS) - 1)];
            if (($this->now)() - $start + $delay > $maxSeconds) {
                break;
            }
            ($this->sleep)($delay);
            $step++;
            $checks++;
            $b = $this->pending->get($this->userId);
            if ($b === null) {
                return ['outcome' => 'settled elsewhere', 'checks' => $checks];
            }
            foreach ($b['items'] as $it) {
                if ($it['confirmed']) {
                    continue;
                }
                $t = ($this->targetOf)((int) $it['id']);
                if ($t === null || abs($t - (float) $it['target']) > PendingSets::TOLERANCE_C) {
                    continue;
                }
                $done = $this->pending->confirm($this->userId, (int) $it['id'], $t);
                if ($done !== null) {
                    $this->announce($done);

                    return ['outcome' => 'confirmed', 'checks' => $checks];
                }
            }
        }
        $left = $this->pending->take($this->userId);
        if ($left === null) {
            return ['outcome' => 'settled elsewhere', 'checks' => $checks];
        }
        $this->announce($left);

        return ['outcome' => 'deadline', 'checks' => $checks];
    }

    /** @param array<string, mixed> $batch */
    public function announce(array $batch): void
    {
        ($this->deliver)(self::text($batch), isset($batch['conversation_id']) ? (int) $batch['conversation_id'] : null);
    }

    /**
     * What the user is told: all confirmed / some did not / none did — in the language of the request, with one temperature when they all share it
     * ("…are now set to 16°: Stue, Badeværelse") or per valve when they differ ("Stue 16°, Badeværelse 20°").
     *
     * @param array<string, mixed> $batch a PendingSets batch
     */
    public static function text(array $batch): string
    {
        $danish = !empty($batch['danish']);
        $items  = array_values($batch['items'] ?? []);
        $ok     = array_values(array_filter($items, static fn (array $i): bool => !empty($i['confirmed'])));
        $left   = array_values(array_filter($items, static fn (array $i): bool => empty($i['confirmed'])));
        $deg    = static fn (float $c): string => rtrim(rtrim(number_format($c, 1, $danish ? ',' : '.', ''), '0'), $danish ? ',' : '.') . '°';
        $same   = count(array_unique(array_map(static fn (array $i): string => (string) (float) $i['target'], $items))) === 1;
        $names  = static fn (array $its): string => implode(', ', array_map(static fn (array $i): string => $i['name'] . ($same ? '' : ' ' . $deg((float) $i['target'])), $its));
        $t      = $same && $items !== [] ? ' ' . $deg((float) $items[0]['target']) : '';
        if ($left === []) {
            return $danish
                ? 'Radiatorerne er nu sat' . ($same ? ' til' . $t . ': ' : ': ') . $names($ok) . '.'
                : 'The radiators are now set' . ($same ? ' to' . $t . ': ' : ': ') . $names($ok) . '.';
        }
        if ($ok === []) {
            return $danish
                ? 'Ingen af radiatorerne har bekræftet' . $t . ' endnu (' . $names($left) . '). De kan være uden for rækkevidde — tjek dem om lidt.'
                : 'None of the radiators has confirmed' . $t . ' yet (' . $names($left) . '). They may be out of reach — check them in a bit.';
        }

        return $danish
            ? $names($ok) . ' er nu sat' . ($same ? ' til' . $t : '') . ', men ' . $names($left) . ' har ikke bekræftet endnu.'
            : $names($ok) . ' now set' . ($same ? ' to' . $t : '') . ', but ' . $names($left) . ' has not confirmed yet.';
    }
}
