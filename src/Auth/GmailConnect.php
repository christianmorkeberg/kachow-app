<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\EmailAccounts;
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use RuntimeException;

/**
 * OAuth flow for connecting a Gmail mailbox as an EMAIL account (separate from
 * the calendar flow in GoogleOAuth, and stored per-account in email_accounts,
 * not on the user row).
 *
 * Scopes: gmail.readonly + gmail.compose (drafts). NOT gmail.send — sending is
 * kept out of the grant for now; drafting is the ceiling. Reuses the same
 * redirect URI as calendar (/api/google-callback.php), which google-callback.php
 * routes by intent, so no new redirect URI needs registering in the console.
 */
final class GmailConnect
{
    public function __construct(
        private EmailAccounts $accounts,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    public static function fromEnv(EmailAccounts $accounts, ?string $redirectUri = null): self
    {
        $clientId     = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET missing from environment.');
        }
        $redirectUri ??= $_ENV['GOOGLE_REDIRECT_URI'] ?? self::guessRedirectUri();

        return new self($accounts, $clientId, $clientSecret, $redirectUri);
    }

    public function consentUrl(?string $state = null): string
    {
        $client = $this->baseClient();
        if ($state !== null && $state !== '') {
            $client->setState($state);
        }

        return $client->createAuthUrl();
    }

    /**
     * Exchange the callback code, discover which Gmail address was granted, and
     * store the mailbox (with its encrypted refresh token) against the app user.
     *
     * @return string the connected email address
     */
    public function handleCallback(string $code, int $userId): string
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
            throw new RuntimeException('No refresh token returned. Re-authorize with prompt=consent.');
        }

        // Identify the granted mailbox via the Gmail profile (readonly scope covers it).
        $profile = (new Gmail($client))->users->getProfile('me');
        $email   = (string) $profile->getEmailAddress();
        if ($email === '') {
            throw new RuntimeException('Could not read the Gmail address for this account.');
        }

        $this->accounts->upsert($userId, 'gmail', $email, $email, ['refresh_token' => $refresh]);

        return $email;
    }

    private function baseClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        // Read + draft. Deliberately no gmail.send while sending is locked.
        $client->setScopes([Gmail::GMAIL_READONLY, Gmail::GMAIL_COMPOSE]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
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
