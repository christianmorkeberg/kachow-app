-- A weight / reps changed on the Train card BEFORE the set is ticked is saved at once (so it survives a reload, the timer trip, or another device).
-- One row per person, day, exercise and set; ticking the set (which writes the real log row) removes it. Run AFTER the deploy: kdb < migrations/2026-11-23_train_overrides.sql
-- The code tolerates the table being missing (the change then lives in the browser only, as before).
CREATE TABLE training_set_overrides (
    user_id      INT UNSIGNED NOT NULL,
    session_date DATE NOT NULL,
    exercise     VARCHAR(120) NOT NULL,
    set_no       SMALLINT UNSIGNED NOT NULL,
    weight       DECIMAL(6,2) NULL,
    reps         SMALLINT UNSIGNED NULL,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, session_date, exercise, set_no),
    CONSTRAINT fk_tso_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
