<?php

declare(strict_types=1);

namespace App\Home;

/**
 * After "set the radiators to 16" ended as "sent" (the valves are asleep and apply it at their next wake-up), this waits for each valve to REPORT the new target
 * and then tells the user once: "Radiators are now set to 16° …", or which valve has not confirmed after a while. It runs after the reply (App\Support\AfterReply),
 * so nobody waits for it. Only the valves still pending are read, a few seconds apart at first and then a minute apart — the home agent handles one command
 * at a time, so constant polling would block the user's lights.
 *
 * Time, reading and delivery are injected: the unit test runs it with a fake clock and no sleeping.
 */
final class ThermostatConfirm
{
    /** Seconds to wait before each check; the last value repeats until the time is up. */
    private const DELAYS = [20, 30, 40, 60];

    /** A valve counts as confirmed when its reported target is within this of what was asked (the valves work in half degrees). */
    private const TOLERANCE_C = 0.3;

    /**
     * @param callable(int):?float                 $targetOf  the valve's reported target in °C, null when it could not be read
     * @param callable(string,string):void         $notify    (title, body) — the push
     * @param callable(string):void                $say       the same text as an assistant message in the conversation
     * @param callable(int):void                   $sleep     seconds
     * @param callable():int                       $now       unix seconds
     */
    public function __construct(private $targetOf, private $notify, private $say, private $sleep, private $now)
    {
    }

    /**
     * @param list<array{id:int, name:string, target:float}> $items
     *
     * @return array{confirmed: list<string>, pending: list<string>, checks: int}
     */
    public function run(array $items, bool $danish, int $maxSeconds = 480): array
    {
        $pending = [];
        foreach ($items as $it) {
            $pending[$it['id']] = $it;          // the same valve set twice: the last ask counts
        }
        $all       = array_values($pending);    // the asks that count, in order
        $confirmed = [];
        $start     = ($this->now)();
        $checks    = 0;
        $step      = 0;

        while ($pending !== []) {
            $delay = self::DELAYS[min($step, count(self::DELAYS) - 1)];
            if (($this->now)() - $start + $delay > $maxSeconds) {
                break;
            }
            ($this->sleep)($delay);
            $step++;
            $checks++;
            foreach ($pending as $id => $it) {
                $t = ($this->targetOf)($id);
                if ($t !== null && abs($t - $it['target']) <= self::TOLERANCE_C) {
                    $confirmed[] = $it;
                    unset($pending[$id]);
                }
            }
        }

        $left = array_values($pending);
        if ($all !== []) {
            $this->tell($confirmed, $left, $danish);
        }

        return [
            'confirmed' => array_map(static fn (array $it): string => $it['name'], $confirmed),
            'pending'   => array_map(static fn (array $it): string => $it['name'], $left),
            'checks'    => $checks,
        ];
    }

    /**
     * @param list<array{id:int, name:string, target:float}> $ok
     * @param list<array{id:int, name:string, target:float}> $left
     */
    private function tell(array $ok, array $left, bool $danish): void
    {
        $deg = static fn (float $c): string => rtrim(rtrim(number_format($c, 1, $danish ? ',' : '.', ''), '0'), $danish ? ',' : '.') . '°';
        // One temperature for all of them: "Stue, Bad" + "16°". Different ones in one request: "Stue 16°, Bad 20°".
        $same  = count(array_unique(array_map(static fn (array $it): string => (string) $it['target'], array_merge($ok, $left)))) === 1;
        $names = static fn (array $its): string => implode(', ', array_map(static fn (array $it): string => $it['name'] . ($same ? '' : ' ' . $deg($it['target'])), $its));
        $t     = $same ? ' ' . $deg(array_merge($ok, $left)[0]['target']) : '';
        if ($left === []) {
            $body = $danish
                ? 'Radiatorerne er nu sat' . ($same ? ' til' . $t . ': ' : ': ') . $names($ok) . '.'
                : 'The radiators are now set' . ($same ? ' to' . $t . ': ' : ': ') . $names($ok) . '.';
        } elseif ($ok === []) {
            $body = $danish
                ? 'Ingen af radiatorerne har bekræftet' . $t . ' endnu (' . $names($left) . '). De kan være uden for rækkevidde — tjek dem om lidt.'
                : 'None of the radiators has confirmed' . $t . ' yet (' . $names($left) . '). They may be out of reach — check them in a bit.';
        } else {
            $body = $danish
                ? $names($ok) . ' er nu sat' . ($same ? ' til' . $t : '') . ', men ' . $names($left) . ' har ikke bekræftet endnu.'
                : $names($ok) . ' now set' . ($same ? ' to' . $t : '') . ', but ' . $names($left) . ' has not confirmed yet.';
        }
        ($this->say)($body);
        ($this->notify)('Kachow', $body);
    }
}
