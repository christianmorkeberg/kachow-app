-- One-off reminders the user asks the assistant to set ("remind me to … at …").
-- A 5-minute cron (bin/reminders-cron.php) delivers due ones as a Web Push, then
-- marks them sent. remind_at is stored in UTC. Run once on the server DB.

CREATE TABLE reminders (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    remind_at  DATETIME     NOT NULL,                 -- UTC
    text       VARCHAR(500) NOT NULL,
    status     ENUM('pending','sent','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_due (status, remind_at),
    CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
