-- Scheduled tasks: a standing natural-language instruction ("check my email for
-- invoices and notify me if you find anything") that a cron job (bin/scheduled-
-- tasks-cron.php) re-runs on a schedule by feeding it back into AssistantLoop as if
-- the user had just typed it, with full tool access. Each task owns a dedicated
-- conversation (conversation_id) so every run's reasoning/tool calls stay visible.
-- next_run_at is stored in UTC; time_of_day/day_of_week are the user's wall-clock
-- schedule (Europe/Copenhagen), resolved to next_run_at by App\Data\ScheduledTasks.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE scheduled_tasks (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NOT NULL,
    label           VARCHAR(80)  NOT NULL,
    instruction     TEXT         NOT NULL,
    frequency       ENUM('hourly','daily','weekly') NOT NULL,
    time_of_day     TIME NULL,                         -- local wall-clock; NULL for hourly
    day_of_week     TINYINT UNSIGNED NULL,              -- ISO 1=Mon..7=Sun; only for weekly
    status          ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    next_run_at     DATETIME NOT NULL,                  -- UTC
    last_run_at     DATETIME NULL,                      -- UTC
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_due (status, next_run_at),
    CONSTRAINT fk_schtask_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_schtask_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
