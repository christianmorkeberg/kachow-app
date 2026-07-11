-- Work-log: a free-text record of what the user did at each job, day by day.
-- Jobs (e.g. DTU / DSB) come from the "Arbejde" Google calendar; hours are entered
-- by the user. Separate from work_events (geofence clock in/out hours).
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE work_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    log_date    DATE NOT NULL,
    job         VARCHAR(64) NOT NULL,
    hours       DECIMAL(5,2) NULL,
    description TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_work_log_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
