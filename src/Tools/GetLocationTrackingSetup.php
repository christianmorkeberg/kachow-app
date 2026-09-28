<?php

declare(strict_types=1);

namespace App\Tools;

use App\Data\ApiTokens;
use App\Data\LocationPoints;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Tool: the user's personal OwnTracks upload URL + how to set the app up, and whether
 * points are arriving. Can rotate the token if the URL leaked. (Location tracking phase 1.)
 */
final class GetLocationTrackingSetup implements Tool
{
    public const SCOPE = 'location';

    public function __construct(private ApiTokens $tokens, private LocationPoints $points)
    {
    }

    public function name(): string
    {
        return 'get_location_tracking_setup';
    }

    public function description(): string
    {
        return 'Gives the user their private OwnTracks upload URL and the steps to set up the OwnTracks '
            . 'iPhone app for location tracking, plus whether points are arriving (last received). Use when '
            . 'they ask to set up / check location tracking or OwnTracks. rotate=true issues a new URL and '
            . 'invalidates the old one (if it leaked).';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'rotate' => ['type' => 'boolean', 'description' => 'Issue a new URL and invalidate the old one.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, int $userId): array
    {
        $rotate = !empty($arguments['rotate']);
        $token  = $rotate ? $this->tokens->rotate($userId, self::SCOPE) : $this->tokens->ensure($userId, self::SCOPE);
        $host   = $_SERVER['HTTP_HOST'] ?? 'assistant.kachow.dk';

        $latest = $this->points->latest($userId);
        $status = 'No points received yet.';
        if ($latest !== null) {
            $at     = (new DateTimeImmutable($latest['recorded_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Copenhagen'));
            $status = 'Last point taken ' . $at->format('D j M H:i')
                . ($latest['device'] !== '' ? ' from "' . $latest['device'] . '"' : '')
                . ($latest['batt'] !== null ? ' (battery ' . $latest['batt'] . '%)' : '') . '.';
        }

        return [
            'rotated'     => $rotate,
            'upload_url'  => 'https://' . $host . '/api/owntracks.php?t=' . $token,
            'status'      => $status,
            'setup_steps' => [
                'Install "OwnTracks" from the App Store.',
                'Allow location "Always" with Precise Location on, and keep Background App Refresh on.',
                'In OwnTracks settings: Mode = HTTP, and paste the upload URL as the URL. No username/password needed.',
                'Set a Device ID (e.g. "iphone") so points are labelled.',
                'On the map screen, switch the monitoring mode to "Move" (continuous tracking).',
                'For a ~5-minute rhythm while moving: locatorInterval 300 and locatorDisplacement 100 (metres) '
                    . 'in the advanced settings.',
                'Do NOT add regions in OwnTracks — Kachow works out places itself from the raw points.',
            ],
            'retention' => 'Raw points are kept ' . LocationPoints::RETENTION_DAYS . ' days, private to you.',
            'note'      => 'Keep this URL private — anyone with it can upload locations to your account. Ask me to '
                . 'rotate it if it leaks.',
        ];
    }
}
