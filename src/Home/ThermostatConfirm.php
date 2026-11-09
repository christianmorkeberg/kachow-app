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

    /** After this long without confirmation the user is told it did not work (and which valves). */
    public const DEADLINE_S = 480;

    /** A valve that still has not confirmed is sent its target AGAIN this long after its last send: first and second re-send (then it is left to the deadline). */
    private const RESEND_AFTER = [90, 240];

    /**
     * @param callable(int):?float            $targetOf the valve's reported target in °C, null when it could not be read
     * @param callable(string, ?int):void     $deliver  (text, conversation id): the push and the message in the chat
     * @param callable(int):void              $sleep    seconds
     * @param callable():int                  $now      unix seconds
     * @param (callable(int, float):bool)|null $resend  sends a valve its target again (the first command may have been dropped); null = never
     */
    public function __construct(private PendingSets $pending, private int $userId, private $targetOf, private $deliver, private $sleep, private $now, private $resend = null)
    {
    }

    /**
     * One look at the pending valves, without waiting: confirms those that report the asked target, sends the others their target again when it is time, and at the
     * deadline tells the user what did not arrive. This is the per-minute safety net's step (bin/thermostat-watch-cron.php) — it does not depend on a request that
     * keeps running after the reply. A batch a live watcher touched less than $minIdleS ago is left alone.
     *
     * @return array{outcome: string} none | busy | confirmed | waiting | deadline
     */
    public function tick(int $minIdleS = 0): array
    {
        $now = ($this->now)();
        $b   = $this->pending->get($this->userId);
        if ($b === null) {
            return ['outcome' => 'none'];
        }
        if ($minIdleS > 0 && $now - (int) ($b['checked_at'] ?? $b['at'] ?? $now) < $minIdleS) {
            return ['outcome' => 'busy'];
        }
        foreach ($b['items'] as $it) {
            if (!empty($it['confirmed'])) {
                continue;
            }
            $t = ($this->targetOf)((int) $it['id']);
            if ($t === null || abs($t - (float) $it['target']) > PendingSets::TOLERANCE_C) {
                continue;
            }
            $done = $this->pending->confirm($this->userId, (int) $it['id'], $t);
            if ($done !== null) {
                $this->announce($done);

                return ['outcome' => 'confirmed'];
            }
        }
        $b = $this->pending->get($this->userId);
        if ($b === null) {
            return ['outcome' => 'none'];
        }
        if ($now - (int) ($b['at'] ?? $now) >= self::DEADLINE_S) {
            $left = $this->pending->take($this->userId);
            if ($left !== null) {
                $this->pending->recordFailures($this->userId, $left);
                $this->announce($left);

                return ['outcome' => 'deadline'];
            }

            return ['outcome' => 'none'];
        }
        $this->pending->markChecked($this->userId, $now, $this->resendDue($b, $now));

        return ['outcome' => 'waiting'];
    }

    /**
     * Sends the valves that are still pending their target again, when it is time (90 s after the last send, then 240 s) — a command can be dropped (the home
     * agent's queue keeps one only 3 minutes) or a valve may not have woken. Harmless: it is the same target. Returns the ids sent.
     *
     * @param array<string, mixed> $b a PendingSets batch
     * @return list<int>
     */
    private function resendDue(array $b, int $now): array
    {
        $sent = [];
        if ($this->resend === null) {
            return $sent;
        }
        foreach ($b['items'] as $it) {
            if (!empty($it['confirmed'])) {
                continue;
            }
            $n = (int) ($it['resends'] ?? 0);
            if ($n >= count(self::RESEND_AFTER) || $now - (int) ($it['sent_at'] ?? $b['at'] ?? $now) < self::RESEND_AFTER[$n]) {
                continue;
            }
            try {
                ($this->resend)((int) $it['id'], (float) $it['target']);
            } catch (\Throwable $e) {
                error_log('ThermostatConfirm resend: ' . $e->getMessage());
            }
            $sent[] = (int) $it['id'];
        }

        return $sent;
    }

    /** @return array{outcome: string, checks: int} outcome: confirmed | deadline | settled elsewhere */
    public function run(int $maxSeconds = self::DEADLINE_S): array
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
            $b = $this->pending->get($this->userId);
            if ($b !== null) {
                $this->pending->markChecked($this->userId, ($this->now)(), $this->resendDue($b, ($this->now)()));
            }
        }
        $left = $this->pending->take($this->userId);
        if ($left === null) {
            return ['outcome' => 'settled elsewhere', 'checks' => $checks];
        }
        $this->pending->recordFailures($this->userId, $left);
        $this->announce($left);

        return ['outcome' => 'deadline', 'checks' => $checks];
    }

    /** @param array<string, mixed> $batch */
    public function announce(array $batch): void
    {
        // Asked by voice and everything arrived: the user was told "okay" out loud and the Home card shows the status — no second confirmation by push. Anything
        // that did NOT arrive is always announced.
        if (!empty($batch['voice']) && array_filter((array) ($batch['items'] ?? []), static fn (array $i): bool => empty($i['confirmed'])) === []) {
            return;
        }
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
        $resent = max(array_map(static fn (array $i): int => (int) ($i['resends'] ?? 0), $left));
        $again  = $resent > 0
            ? ($danish ? ' Jeg har sendt den igen ' . $resent . ($resent === 1 ? ' gang' : ' gange') . '.' : ' I sent it again ' . $resent . ($resent === 1 ? ' time' : ' times') . '.')
            : '';
        $retry  = $danish ? ' Sig “prøv igen” for at sende den på ny.' : ' Say “try again” to send it once more.';
        if ($ok === []) {
            return $danish
                ? 'Ingen af radiatorerne har bekræftet' . $t . ' (' . $names($left) . ').' . $again . ' De kan være uden for rækkevidde eller have fladt batteri.' . $retry
                : 'None of the radiators has confirmed' . $t . ' (' . $names($left) . ').' . $again . ' They may be out of reach or have a flat battery.' . $retry;
        }

        return $danish
            ? $names($ok) . ' er nu sat' . ($same ? ' til' . $t : '') . ', men ' . $names($left) . ' har ikke bekræftet.' . $again . $retry
            : $names($ok) . ' now set' . ($same ? ' to' . $t : '') . ', but ' . $names($left) . ' has not confirmed.' . $again . $retry;
    }
}
