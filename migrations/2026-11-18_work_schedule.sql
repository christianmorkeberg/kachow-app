-- Work schedule + days off, to keep track of being ahead / behind. Flexible on purpose: the schedule is DATA the user tells the assistant (hours per weekday, from a date,
-- optionally per workplace), never built in. Days off (holiday, sick, public holiday, other) credit that day's hours so they never put you behind.
-- Balance = worked + credited − target, counted from the first schedule's start date with an opening balance of 0.
-- Run AFTER the deploy: kdb < migrations/2026-11-18_work_schedule.sql   (the code tolerates the tables missing)
CREATE TABLE work_schedule (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    place          VARCHAR(64) NULL,                 -- NULL = the combined target; a workplace name = that workplace's own
    hours          VARCHAR(120) NOT NULL,            -- JSON {"1":7.4,"2":7.4,...} hours per ISO weekday (1 = Monday); missing/0 = a day off
    note           VARCHAR(255) NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ws_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_ws (user_id, effective_from, place)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE work_absences (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    absence_date DATE NOT NULL,
    kind         VARCHAR(16) NOT NULL DEFAULT 'holiday',   -- holiday | sick | public_holiday | other
    hours        DECIMAL(4,2) NULL,                         -- NULL = that day's full target; a number = a part of the day
    place        VARCHAR(64) NULL,
    note         VARCHAR(255) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_wa (user_id, absence_date, place),
    INDEX idx_wa_user_date (user_id, absence_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
