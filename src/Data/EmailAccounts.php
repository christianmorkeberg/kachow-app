<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use App\Support\Encryptor;
use PDO;

/**
 * Per-user connected mailboxes. Credentials (OAuth refresh token / IMAP
 * app-password) are stored ENCRYPTED and only ever decrypted in-process when a
 * provider needs them — never returned to the model or the frontend.
 *
 * Rows are scoped to the owning user on every read/write; there is no cross-user
 * access path here (email is strictly personal, unlike shared calendars).
 */
final class EmailAccounts
{
    public const PROVIDERS = ['gmail', 'outlook', 'imap'];

    private PDO $db;
    private Encryptor $enc;

    public function __construct(?PDO $db = null, ?Encryptor $enc = null)
    {
        $this->db  = $db ?? Database::get();
        $this->enc = $enc ?? new Encryptor();
    }

    /**
     * Insert or update a connection (same user+provider+email reconnects in place).
     * $credentials is a plain associative array; it is JSON-encoded then encrypted.
     *
     * @param array<string, mixed> $credentials
     */
    public function upsert(
        int $userId,
        string $provider,
        string $email,
        ?string $displayName,
        array $credentials,
    ): int {
        $provider = in_array($provider, self::PROVIDERS, true) ? $provider : 'imap';
        $blob     = $this->enc->encrypt((string) json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $stmt = $this->db->prepare(
            'INSERT INTO email_accounts (user_id, provider, email, display_name, credentials, status)
             VALUES (:u, :p, :e, :d, :c, "active")
             ON DUPLICATE KEY UPDATE
                 display_name = VALUES(display_name),
                 credentials  = VALUES(credentials),
                 status       = "active"'
        );
        $stmt->execute([
            ':u' => $userId,
            ':p' => $provider,
            ':e' => mb_substr($email, 0, 255),
            ':d' => $displayName !== null ? mb_substr($displayName, 0, 120) : null,
            ':c' => $blob,
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        // ON DUPLICATE UPDATE path: fetch the existing row's id.
        $find = $this->db->prepare(
            'SELECT id FROM email_accounts WHERE user_id = :u AND provider = :p AND email = :e'
        );
        $find->execute([':u' => $userId, ':p' => $provider, ':e' => mb_substr($email, 0, 255)]);

        return (int) $find->fetchColumn();
    }

    /**
     * Active accounts for a user, WITHOUT decrypted credentials — safe to surface.
     *
     * @return array<int, array{id:int, provider:string, email:string, display_name:?string, status:string}>
     */
    public function listForUser(int $userId, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, provider, email, display_name, status
                FROM email_accounts WHERE user_id = :u';
        if ($activeOnly) {
            $sql .= ' AND status = "active"';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':u' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'provider'     => (string) $r['provider'],
                'email'        => (string) $r['email'],
                'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : null,
                'status'       => (string) $r['status'],
            ];
        }

        return $out;
    }

    /**
     * Public metadata for one account (no credentials), scoped to the user.
     *
     * @return array{id:int, provider:string, email:string, display_name:?string, status:string}|null
     */
    public function get(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, provider, email, display_name, status
             FROM email_accounts WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $r = $stmt->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'id'           => (int) $r['id'],
            'provider'     => (string) $r['provider'],
            'email'        => (string) $r['email'],
            'display_name' => $r['display_name'] !== null ? (string) $r['display_name'] : null,
            'status'       => (string) $r['status'],
        ];
    }

    /**
     * Decrypted credentials for one account — for provider use only. Never expose
     * the result outside the Email layer.
     *
     * @return array<string, mixed>|null
     */
    public function credentials(int $userId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT credentials FROM email_accounts WHERE id = :id AND user_id = :u AND status = "active"'
        );
        $stmt->execute([':id' => $id, ':u' => $userId]);
        $blob = $stmt->fetchColumn();
        if ($blob === false || $blob === null) {
            return null;
        }

        $json = $this->enc->decrypt((string) $blob);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    public function setStatus(int $userId, int $id, string $status): bool
    {
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $stmt   = $this->db->prepare(
            'UPDATE email_accounts SET status = :s WHERE id = :id AND user_id = :u'
        );
        $stmt->execute([':s' => $status, ':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM email_accounts WHERE id = :id AND user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
