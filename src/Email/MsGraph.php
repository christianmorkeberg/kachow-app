<?php

declare(strict_types=1);

namespace App\Email;

use RuntimeException;

/**
 * Thin Microsoft Graph helper (raw cURL, no SDK) shared by OutlookProvider and
 * the OutlookConnect OAuth flow. Handles the OAuth token endpoints and authorised
 * Graph REST calls. Uses the /common authority so both personal Microsoft accounts
 * (Hotmail/Outlook.com) and work/school accounts can connect.
 *
 * Read + draft scopes only (Mail.ReadWrite); Mail.Send is deliberately omitted
 * while sending is locked.
 */
final class MsGraph
{
    public const AUTHORITY = 'https://login.microsoftonline.com/common/oauth2/v2.0';
    public const GRAPH     = 'https://graph.microsoft.com/v1.0';
    public const SCOPES    = 'offline_access User.Read Mail.ReadWrite';

    /**
     * Exchange an authorization code for tokens (access + refresh).
     *
     * @return array<string, mixed>
     */
    public static function tokenFromCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        return self::token([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'scope'         => self::SCOPES,
        ]);
    }

    /**
     * Mint a fresh access token (and possibly rotated refresh token) from a stored
     * refresh token.
     *
     * @return array<string, mixed>
     */
    public static function tokenFromRefresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        return self::token([
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => self::SCOPES,
        ]);
    }

    /**
     * Authorised Graph request. $path is relative to GRAPH (e.g. "/me/messages?...").
     * $json (when given) is sent as the JSON body. $extraHeaders lets callers add
     * e.g. a Prefer header. Returns the decoded JSON body (empty array for 204).
     *
     * @param array<string, mixed>|null $json
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    public static function request(
        string $accessToken,
        string $method,
        string $path,
        ?array $json = null,
        array $extraHeaders = [],
    ): array {
        $ch      = curl_init();
        $headers = array_merge([
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ], $extraHeaders);

        curl_setopt($ch, CURLOPT_URL, str_starts_with($path, 'http') ? $path : self::GRAPH . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Microsoft Graph request failed: ' . $err);
        }
        if ($status === 204 || $body === '') {
            return [];
        }
        $decoded = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RuntimeException('Microsoft Graph error (HTTP ' . $status . ')' . ($message !== null ? ': ' . $message : ''));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private static function token(array $fields): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::AUTHORITY . '/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Microsoft token request failed: ' . $err);
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || $status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? null) : null;
            throw new RuntimeException('Microsoft token exchange failed' . ($message !== null ? ': ' . $message : ' (HTTP ' . $status . ')'));
        }

        return $decoded;
    }
}
