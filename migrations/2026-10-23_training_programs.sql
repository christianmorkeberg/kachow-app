-- Training programmes: a multi-week plan stored properly (programme → days → exercises) instead of as a note in the assistant's memory.
-- A day of an active programme becomes that date's workout plan when it is asked for (see Support\ProgramPlanner).
-- Additive; run once before deploying the code that uses it.

CREATE TABLE training_programs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    status        ENUM('active','archived') NOT NULL DEFAULT 'active',
    week_mode     ENUM('calendar','relative') NOT NULL DEFAULT 'calendar',   -- calendar: weeks are ISO week numbers ("uge 43"); relative: week 1, 2, 3 … from start_date
    start_date    DATE NULL,                                                  -- relative: any date in week 1
    repeat_weeks  TINYINT UNSIGNED NULL,                                      -- relative: the programme repeats every N weeks (a 4-week block)
    source_note   MEDIUMTEXT NULL,                                            -- the user's own note it was made from, kept so it can be re-read
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_status (user_id, status),
    CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE training_program_days (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_id  INT UNSIGNED NOT NULL,
    week_no     SMALLINT UNSIGNED NOT NULL,         -- ISO week number (calendar) or week 1..n (relative)
    year        SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- ISO year for calendar weeks; 0 = any year
    weekday     TINYINT UNSIGNED NOT NULL,          -- 1 = Monday … 7 = Sunday
    title       VARCHAR(120) NULL,
    note        VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_day (program_id, year, week_no, weekday),
    CONSTRAINT fk_tpd_program FOREIGN KEY (program_id) REFERENCES training_programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE training_program_items (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    day_id     INT UNSIGNED NOT NULL,
    position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    exercise   VARCHAR(120) NOT NULL,
    sets       SMALLINT UNSIGNED NULL,
    reps       SMALLINT UNSIGNED NULL,
    weight     DECIMAL(6,2) NULL,
    note       VARCHAR(255) NULL,                   -- anything that is not a plain number: "8–10", "to failure", "alt: pull-ups"
    PRIMARY KEY (id),
    KEY idx_day (day_id),
    CONSTRAINT fk_tpi_day FOREIGN KEY (day_id) REFERENCES training_program_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
