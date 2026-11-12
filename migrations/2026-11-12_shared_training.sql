-- Shared training: a programme can belong to a PAIR of connected people (both can see and edit it, each day says who it is for), exercises can carry a percentage of the
-- person's training max and a per-set plan (50 / 60 / 70 %), each person can have a training-max override per exercise, and the sets logged from the Train card are tied to a session
-- with what was planned next to what was done.
-- Run once on the server DB (kachowdk_ai), AFTER the deploy:  kdb < migrations/2026-11-12_shared_training.sql
-- (The app works before and after this runs: the new columns are only used once they exist.)

ALTER TABLE training_programs      ADD COLUMN connection_id INT UNSIGNED NULL AFTER user_id,           -- the pair it is shared with (user_connections.id); NULL = just the owner's
                                   ADD INDEX idx_tp_conn (connection_id);
ALTER TABLE training_program_days  ADD COLUMN audience_user_id INT UNSIGNED NULL AFTER weekday;         -- who the day is for: NULL = everyone on the programme, else that one person
ALTER TABLE training_program_items ADD COLUMN percent DECIMAL(5,2) NULL AFTER weight,                  -- % of the person's training max (all sets), or NULL
                                   ADD COLUMN set_plan TEXT NULL AFTER percent;                         -- JSON [{reps, percent, weight}, …] when the sets differ (a ramp); NULL = every set the same

CREATE TABLE IF NOT EXISTS training_maxes (
    user_id      INT UNSIGNED NOT NULL,
    exercise     VARCHAR(120) NOT NULL,
    training_max DECIMAL(7,2) NOT NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, exercise)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_sessions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id   INT UNSIGNED NULL,
    session_date DATE NOT NULL,
    week_no      SMALLINT UNSIGNED NULL,
    weekday      TINYINT UNSIGNED NULL,
    started_at   DATETIME NOT NULL,
    last_tick_at DATETIME NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    INDEX idx_ts_date (session_date),
    INDEX idx_ts_program (program_id, session_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE workouts ADD COLUMN session_id INT UNSIGNED NULL,
                     ADD COLUMN set_no SMALLINT UNSIGNED NULL,
                     ADD COLUMN planned_percent DECIMAL(5,2) NULL,
                     ADD COLUMN planned_weight DECIMAL(6,2) NULL,
                     ADD INDEX idx_workouts_session (session_id);
