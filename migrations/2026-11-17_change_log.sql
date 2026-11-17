-- A log of what the assistant changed or deleted, with what it looked like BEFORE, so a mistake can be undone ("bring back that work session").
-- Written by the tools that edit or delete (work sessions, cash movements so far). Run AFTER the deploy: `kdb < migrations/2026-11-17_change_log.sql`.
-- The code tolerates the table being missing (nothing is logged, restore says so).
CREATE TABLE change_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    entity          VARCHAR(32) NOT NULL,              -- work_session | cash_movement
    entity_id       INT UNSIGNED NULL,
    action          ENUM('update','delete') NOT NULL,
    summary         VARCHAR(255) NOT NULL,             -- one line a person can read
    before_json     MEDIUMTEXT NULL,                   -- what it was (rows), enough to put it back
    after_json      MEDIUMTEXT NULL,
    tool            VARCHAR(64) NULL,
    conversation_id INT UNSIGNED NULL,
    restored_at     DATETIME NULL,                     -- set once it has been put back
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_change_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_change_log_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
