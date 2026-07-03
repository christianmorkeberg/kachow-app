<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\Users;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use RuntimeException;

/**
 * Google OAuth flow for Calendar access (separate from the app's own login).
 *
 * Flow (spec §6):
 *   1. consentUrl() -> redirect the (already app-logged-in) user to Google.
 *   2. Google redirects back to api/google-callback.php with ?code=...
 *   3. handleCallback() exchanges the code, stores the refresh token ENCRYPTED
 *      via Users::setGoogleRefreshToken (this class never touches the DB directly).
 *   4. authorizedClientForUser() later mints a fresh access token from the stored
 *      refresh token for Data/Calendar.php to use.
 */
final class GoogleOAuth
{
    public function __construct(
        private Users $users,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    /**
     * Builds an instance from environment config. The redirect URI defaults to
     * $_ENV['GOOGLE_REDIRECT_URI'] if set, otherwise it is derived from the
     * current request host (works for both localhost:8000 and assistant.kachow.dk,
     * the two registered URIs).
     */
    public static function fromEnv(Users $users, ?string $redirectUri = null): self
    {
        $clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET missing from environment.');
        }

        $redirectUri ??= $_ENV['GOOGLE_REDIRECT_URI'] ?? self::guessRedirectUri();

        return new self($users, $clientId, $clientSecret, $redirectUri);
    }

    /**
     * The URL to send the user to in order to grant calendar access.
     */
    public function consentUrl(?string $state = null): string
    {
        $client = $this->baseClient();
        if ($state !== null && $state !== '') {
            // Round-tripped back on the callback for CSRF protection.
            $client->setState($state);
        }

        return $client->createAuthUrl();
    }

    /**
     * Exchanges the callback ?code for tokens and persists the refresh token
     * (encrypted) against the given app user. Throws on failure or if Google did
     * not return a refresh token (which prompt=consent is meant to guarantee).
     */
    public function handleCallback(string $code, int $userId): void
    {
        $client = $this->baseClient();
        $token  = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException(
                'Google token exchange failed: ' . ($token['error_description'] ?? $token['error'])
            );
        }

        $refresh = $token['refresh_token'] ?? null;
        if (!is_string($refresh) || $refresh === '') {
            throw new RuntimeException(
                'No refresh token returned by Google. Re-authorize with prompt=consent.'
            );
        }

        $this->users->setGoogleRefreshToken($userId, $refresh);
    }

    /**
     * True if the user has a stored refresh token (i.e. has connected calendar).
     * Cheap check — does not hit the network.
     */
    public function isConnected(int $userId): bool
    {
        return $this->users->getGoogleRefreshToken($userId) !== null;
    }

    /**
     * Returns a Google client authorized for the user: loads the stored refresh
     * token, mints a fresh access token, and (if Google rotated it) persists the
     * new refresh token. Throws if the user has not connected calendar.
     */
    public function authorizedClientForUser(int $userId): GoogleClient
    {
        $refresh = $this->users->getGoogleRefreshToken($userId);
        if ($refresh === null) {
            throw new RuntimeException('User has not connected Google Calendar.');
        }

        $client = $this->baseClient();
        $token  = $client->fetchAccessTokenWithRefreshToken($refresh);

        if (isset($token['error'])) {
            throw new RuntimeException(
                'Failed to refresh Google access token: ' . ($token['error_description'] ?? $token['error'])
            );
        }

        // Google rarely rotates refresh tokens, but persist it if it did.
        $rotated = $client->getRefreshToken();
        if (is_string($rotated) && $rotated !== '' && $rotated !== $refresh) {
            $this->users->setGoogleRefreshToken($userId, $rotated);
        }

        return $client;
    }

    private function baseClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes([GoogleCalendar::CALENDAR]); // full calendar access
        $client->setAccessType('offline');              // needed for a refresh token
        $client->setPrompt('consent');                  // reliably re-issues one
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    private static function guessRedirectUri(): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

        return $scheme . '://' . $host . '/api/google-callback.php';
    }
}
