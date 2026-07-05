-- Personal memory: lasting facts the assistant learns about the user.
-- Content is encrypted at rest by the app (libsodium, APP_ENCRYPTION_KEY), so
-- this column holds base64(nonce.ciphertext), not plaintext.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE memories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    category   VARCHAR(32) NOT NULL DEFAULT 'general',
    content    TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_memories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_memories_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
