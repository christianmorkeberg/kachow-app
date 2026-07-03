<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;
use RuntimeException;

/**
 * Data-access layer for the `users` table.
 *
 * This is the only place that reads/writes user rows. Per the spec's layering
 * rule, Tools/, Auth/, and any future dashboard call into here rather than
 * touching MySQL directly.
 *
 * The Google refresh token is encrypted at rest with libsodium's secretbox
 * (authenticated XSalsa20-Poly1305). Callers pass/receive the plaintext token;
 * encryption never leaks past this class. Auth/GoogleOAuth.php stores tokens by
 * calling setGoogleRefreshToken() rather than writing the column itself, which
 * keeps both DB access and crypto contained here.
 */
final class Users
{
    /** Env var holding the base64-encoded 32-byte secretbox key. */
    private const ENCRYPTION_KEY_ENV = 'APP_ENCRYPTION_KEY';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Returns the user row for the given id, or null if not found.
     *
     * The row includes the raw (still-encrypted) google_refresh_token column.
     * Use getGoogleRefreshToken() to obtain the decrypted token.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, name, password_hash, role, google_refresh_token, created_at
             FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Returns the user row for the given email, or null if not found.
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, name, password_hash, role, google_refresh_token, created_at
             FROM users WHERE email = :email'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts a new user and returns the new id.
     *
     * Expects an already-hashed password (password_hash() is applied by the
     * auth layer, not here). $role must be 'admin' or 'user'.
     */
    public function create(string $email, string $passwordHash, string $role = 'user', ?string $name = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (email, name, password_hash, role)
             VALUES (:email, :name, :password_hash, :role)'
        );
        $stmt->execute([
            ':email'         => $email,
            ':name'          => $name,
            ':password_hash' => $passwordHash,
            ':role'          => $role,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns the decrypted Google refresh token for the user, or null if the
     * user has never connected their calendar.
     */
    public function getGoogleRefreshToken(int $userId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT google_refresh_token FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $encrypted = $stmt->fetchColumn();

        if ($encrypted === false || $encrypted === null || $encrypted === '') {
            return null;
        }

        return $this->decrypt((string) $encrypted);
    }

    /**
     * Stores the Google refresh token for the user, encrypted at rest.
     * Passing null clears the stored token (e.g. on disconnect).
     */
    public function setGoogleRefreshToken(int $userId, ?string $token): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET google_refresh_token = :token WHERE id = :id'
        );
        $stmt->execute([
            ':token' => $token === null ? null : $this->encrypt($token),
            ':id'    => $userId,
        ]);
    }

    // --- encryption helpers -------------------------------------------------

    private function encrypt(string $plaintext): string
    {
        $key   = $this->encryptionKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Prepend the nonce so decrypt() can recover it; base64 for TEXT storage.
        return base64_encode($nonce . $cipher);
    }

    private function decrypt(string $stored): string
    {
        $key  = $this->encryptionKey();
        $data = base64_decode($stored, true);

        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Stored refresh token is malformed.');
        }

        $nonce  = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        if ($plaintext === false) {
            // Wrong key or tampered ciphertext — never fall back to raw bytes.
            throw new RuntimeException('Refresh token could not be decrypted.');
        }

        return $plaintext;
    }

    /**
     * Loads and validates the 32-byte encryption key from the environment.
     */
    private function encryptionKey(): string
    {
        $raw = $_ENV[self::ENCRYPTION_KEY_ENV] ?? null;

        if ($raw === null || $raw === '') {
            throw new RuntimeException(
                'Missing required environment variable: ' . self::ENCRYPTION_KEY_ENV
            );
        }

        $key = base64_decode((string) $raw, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                self::ENCRYPTION_KEY_ENV . ' must be a base64-encoded '
                . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . '-byte key.'
            );
        }

        return $key;
    }
}
