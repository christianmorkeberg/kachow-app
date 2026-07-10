<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\EmailAccounts;
use App\Email\MsGraph;
use RuntimeException;

/**
 * OAuth flow for connecting an Outlook/Hotmail mailbox via Microsoft Graph,
 * stored per-account in email_accounts (provider = 'outlook'). Mirrors
 * GmailConnect but for the Microsoft identity platform.
 *
 * Scopes: offline_access + User.Read + Mail.ReadWrite (read + drafts). NOT
 * Mail.Send — sending stays locked. Uses its own redirect target
 * (/api/outlook-callback.php), registered in the Azure app.
 */
final class OutlookConnect
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
        $clientId     = $_ENV['MS_CLIENT_ID'] ?? '';
        $clientSecret = $_ENV['MS_CLIENT_SECRET'] ?? '';
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('MS_CLIENT_ID / MS_CLIENT_SECRET missing from environment.');
        }
        $redirectUri ??= $_ENV['MS_REDIRECT_URI'] ?? self::guessRedirectUri();

        return new self($accounts, $clientId, $clientSecret, $redirectUri);
    }

    public function consentUrl(string $state): string
    {
        $params = [
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'response_mode' => 'query',
            'scope'         => MsGraph::SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ];

        return MsGraph::AUTHORITY . '/authorize?' . http_build_query($params);
    }

    /**
     * Exchange the callback code, discover the mailbox address, and store the
     * account (with its encrypted refresh token) against the app user.
     *
     * @return string the connected email address
     */
    public function handleCallback(string $code, int $userId): string
    {
        $token   = MsGraph::tokenFromCode($this->clientId, $this->clientSecret, $code, $this->redirectUri);
        $refresh = (string) ($token['refresh_token'] ?? '');
        $access  = (string) ($token['access_token'] ?? '');
        if ($refresh === '' || $access === '') {
            throw new RuntimeException('Microsoft did not return the expected tokens (need offline_access).');
        }

        $me    = MsGraph::request($access, 'GET', '/me?$select=mail,userPrincipalName,displayName');
        $email = (string) ($me['mail'] ?? $me['userPrincipalName'] ?? '');
        if ($email === '') {
            throw new RuntimeException('Could not read the mailbox address for this account.');
        }
        $name = (string) ($me['displayName'] ?? $email);

        $this->accounts->upsert($userId, 'outlook', $email, $name, ['refresh_token' => $refresh]);

        return $email;
    }

    private static function guessRedirectUri(): string
    {
        $https  = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

        return $scheme . '://' . $host . '/api/outlook-callback.php';
    }
}
