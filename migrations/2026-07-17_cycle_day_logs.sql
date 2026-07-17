-- Per-day mood/energy logging for cycle tracking (distinct from cycle_periods,
-- which is period starts). One row per user per day; mood/energy on a 1–5 scale.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE cycle_day_logs (
    user_id    INT UNSIGNED NOT NULL,
    log_date   DATE NOT NULL,
    mood       TINYINT UNSIGNED NULL,   -- 1..5
    energy     TINYINT UNSIGNED NULL,   -- 1..5
    note       VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, log_date),
    CONSTRAINT fk_cdl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
