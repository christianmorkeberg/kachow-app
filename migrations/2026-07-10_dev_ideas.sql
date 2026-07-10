-- "Capture dev ideas via chat": a lightweight backlog the assistant can jot into
-- when you say things like "for later: …". Run once on the server DB (kachowdk_ai).

CREATE TABLE dev_ideas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    idea       VARCHAR(1000) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dev_ideas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dev_ideas_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
