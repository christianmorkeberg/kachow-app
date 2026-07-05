-- Dynamic workout planning: planned sessions (a date + title) and their exercise
-- items (with optional structured targets so ticking can also log real sets).
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE workout_plans (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    plan_date  DATE NOT NULL,
    title      VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wplans_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wplans_user_date (user_id, plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workout_plan_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    plan_id       INT NOT NULL,
    exercise      VARCHAR(120) NOT NULL,
    target_sets   INT NULL,
    target_reps   INT NULL,
    target_weight DECIMAL(6,2) NULL,
    note          VARCHAR(255) NULL,
    position      INT NOT NULL DEFAULT 0,
    done          TINYINT(1) NOT NULL DEFAULT 0,
    logged        TINYINT(1) NOT NULL DEFAULT 0,
    done_at       TIMESTAMP NULL,
    CONSTRAINT fk_wpitems_plan FOREIGN KEY (plan_id) REFERENCES workout_plans(id) ON DELETE CASCADE,
    INDEX idx_wpitems_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
