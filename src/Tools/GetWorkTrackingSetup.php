<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ApiTokens;

/**
 * Tool: gives the user their personal work clock-in / clock-out URLs and the iOS
 * Shortcuts steps to trigger them on arriving at / leaving work. Can rotate the
 * token if the old URLs leaked.
 */
final class GetWorkTrackingSetup implements Tool
{
    private const SCOPE = 'work_punch';

    public function __construct(private ApiTokens $tokens)
    {
    }

    public function name(): string
    {
        return 'get_work_tracking_setup';
    }

    public function description(): string
    {
        return 'Gives the user their personal work-tracking URLs (one for arriving/clock-in, one for '
            . 'leaving/clock-out) and how to wire them to an iPhone location automation. Use when they '
            . 'ask to set up work-time tracking, or where their tracking link is. If they have more '
            . 'than one workplace, pass their names in "places" and each gets its own labelled URLs '
            . '(so hours are tracked per workplace). Set rotate=true to issue fresh URLs and '
            . 'invalidate the old ones (if a link leaked).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'places' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Workplace names, if the user has more than one (e.g. ["Office", '
                        . '"Clinic"]). Each gets its own labelled clock-in/out URLs. Omit for a single '
                        . 'unlabelled pair.',
                ],
                'rotate' => [
                    'type'        => 'boolean',
                    'description' => 'If true, generate new URLs and invalidate the previous ones.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $token = !empty($arguments['rotate'])
            ? $this->tokens->rotate($userId, self::SCOPE)
            : $this->tokens->ensure($userId, self::SCOPE);

        $base   = $this->baseUrl();
        $places = array_values(array_filter(array_map(
            static fn ($p): string => trim((string) $p),
            is_array($arguments['places'] ?? null) ? $arguments['places'] : []
        ), static fn (string $p): bool => $p !== ''));

        $result = [
            'rotated'     => !empty($arguments['rotate']),
            'setup_steps' => [
                'On your iPhone: Shortcuts app → Automation tab → + → Create Personal Automation.',
                'Choose "Arrive", set Location to the workplace, then Next.',
                'Add action "Get Contents of URL" and paste that workplace\'s clock-in URL.',
                'Turn on "Run Immediately" and turn off "Notify When Run", then Done.',
                'Repeat with a second automation using "Leave" and the clock-out URL.',
            ],
            'note' => 'Keep these URLs private — anyone with them can log time for you. Ask me to '
                . 'rotate them if one leaks.',
        ];

        if ($places === []) {
            $result['clock_in_url']  = $this->url($base, $token, 'in', null);
            $result['clock_out_url'] = $this->url($base, $token, 'out', null);
            return $result;
        }

        // One labelled pair per workplace — do each workplace's Arrive/Leave with its own URLs.
        $result['workplaces'] = array_map(fn (string $name): array => [
            'name'          => $name,
            'clock_in_url'  => $this->url($base, $token, 'in', $name),
            'clock_out_url' => $this->url($base, $token, 'out', $name),
        ], $places);

        return $result;
    }

    private function url(string $base, string $token, string $e, ?string $place): string
    {
        $url = $base . '/api/punch.php?t=' . $token . '&e=' . $e;
        if ($place !== null && $place !== '') {
            $url .= '&p=' . rawurlencode($place);
        }

        return $url;
    }

    /** Best-effort site origin (this tool only runs behind the web app). */
    private function baseUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off';
        $scheme = $https ? 'https' : 'https'; // always advertise https for the automation

        return $scheme . '://' . $host;
    }
}
