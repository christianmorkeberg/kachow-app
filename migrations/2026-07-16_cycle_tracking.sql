-- Menstrual cycle tracking. Each row is one logged period (a start, optional end,
-- optional flow/note). Predictions (next period, phase, fertile window) are derived
-- from the history — nothing predicted is stored. Sensitive health data: strictly
-- per-user, shared only via an explicit 'cycle' connection scope. Run once on the
-- server DB (kachowdk_ai).

CREATE TABLE cycle_periods (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date   DATE NULL,
    flow       ENUM('light','medium','heavy') NULL,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cycle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_cycle_user_start (user_id, start_date),
    INDEX idx_cycle_user_date (user_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
