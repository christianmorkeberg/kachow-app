-- Email integration: one row per connected mailbox (multi-account from the start).
-- Gmail is built first; Outlook (MS Graph) and IMAP slot in later behind the same
-- rows. Provider credentials (OAuth refresh token / IMAP app-password) are stored
-- ENCRYPTED via App\Support\Encryptor (same APP_ENCRYPTION_KEY as the Google token).
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE email_accounts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    provider     ENUM('gmail','outlook','imap') NOT NULL,
    email        VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NULL,
    status       ENUM('active','disabled') NOT NULL DEFAULT 'active',
    -- base64(nonce.ciphertext) of a provider-specific JSON credentials blob
    -- (gmail/outlook: {"refresh_token":"…"}; imap: {"host":…,"port":…,"user":…,"pass":…}).
    credentials  TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    -- One connection per (user, provider, address); reconnecting updates in place.
    UNIQUE KEY uq_email_accounts (user_id, provider, email),
    INDEX idx_email_accounts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
