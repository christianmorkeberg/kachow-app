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
            . 'ask to set up work-time tracking, or where their tracking link is. Set rotate=true to '
            . 'issue fresh URLs and invalidate the old ones (if a link leaked).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
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
        $inUrl  = $base . '/api/punch.php?t=' . $token . '&e=in';
        $outUrl = $base . '/api/punch.php?t=' . $token . '&e=out';

        return [
            'clock_in_url'  => $inUrl,
            'clock_out_url' => $outUrl,
            'rotated'       => !empty($arguments['rotate']),
            'setup_steps'   => [
                'On your iPhone: Shortcuts app → Automation tab → + → Create Personal Automation.',
                'Choose "Arrive", set Location to your workplace, then Next.',
                'Add action "Get Contents of URL" and paste the clock-in URL.',
                'Turn on "Run Immediately" and turn off "Notify When Run", then Done.',
                'Repeat with a second automation using "Leave" and the clock-out URL.',
            ],
            'note' => 'Keep these URLs private — anyone with them can log time for you. Ask me to '
                . 'rotate them if one leaks.',
        ];
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
