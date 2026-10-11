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
        $health = 'unknown';
        if ($latest !== null) {
            $at     = (new DateTimeImmutable($latest['recorded_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('Europe/Copenhagen'));
            $ageMin = (int) round((time() - strtotime($latest['recorded_at'] . ' UTC')) / 60);
            $status = 'Last point taken ' . $at->format('D j M H:i')
                . ($latest['device'] !== '' ? ' from "' . $latest['device'] . '"' : '')
                . ($latest['batt'] !== null ? ' (battery ' . $latest['batt'] . '%)' : '')
                . ' — ' . ($ageMin < 60 ? $ageMin . ' min ago' : round($ageMin / 60, 1) . ' h ago') . '.';
            // Standing still publishes nothing when locatorDisplacement filters it, so a quiet hour
            // is normal; half a day of silence is not.
            $health = $ageMin <= 90 ? 'ok' : ($ageMin <= 12 * 60 ? 'quiet' : 'stopped');
        }

        return [
            'rotated'     => $rotate,
            'upload_url'  => 'https://' . $host . '/api/owntracks.php?t=' . $token,
            'status'      => $status,
            'health'      => $health,
            'if_stopped'  => 'When health is "stopped", OwnTracks has quietly died: tell the user to open the app '
                . '(that alone revives it), check the monitoring mode is Move, that Location is "Always" with '
                . 'Precise on and Background App Refresh is on, that Low Power Mode is off — and not to swipe the '
                . 'app away, which stops publishing until it is reopened. Tapping publish sends one point now.',
            'setup_steps' => [
                'Install "OwnTracks" from the App Store.',
                'Allow location "Always" with Precise Location on, and keep Background App Refresh on.',
                'In OwnTracks settings: Mode = HTTP, and paste the upload URL as the URL. No username/password needed.',
                'Set a Device ID (e.g. "iphone") so points are labelled.',
                'On the map screen, switch the monitoring mode to "Move" (continuous tracking).',
                'For a steady 5-minute rhythm: locatorInterval 300 and locatorDisplacement 0 in the advanced '
                    . 'settings. 0 means it also reports while standing still, which keeps arrival and departure '
                    . 'times sharp and makes silence mean something is wrong (so the "Tracking stopped" alert works).',
                'Do NOT add regions in OwnTracks — Kachow works out places itself from the raw points.',
            ],
            'retention' => 'Raw points are kept ' . LocationPoints::RETENTION_DAYS . ' days, private to you.',
            'note'      => 'Keep this URL private — anyone with it can upload locations to your account. Ask me to '
                . 'rotate it if it leaks.',
        ];
    }
}
