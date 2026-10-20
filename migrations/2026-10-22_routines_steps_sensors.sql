-- Routines, part 2: recorded exact steps + undo, and sensor triggers. Additive; run once, before deploying the code that uses it.

ALTER TABLE scheduled_tasks
    MODIFY trigger_type ENUM('time','phrase','place','sensor') NOT NULL DEFAULT 'time',
    ADD COLUMN steps TEXT NULL AFTER conditions,            -- the exact home-device calls a run made (replayed instead of asking the model)
    ADD COLUMN undo_steps TEXT NULL AFTER steps,            -- what puts the lights back after the last replay
    ADD COLUMN undo_at DATETIME NULL AFTER undo_steps;

-- Whether a sensor routine's condition was true at the last reading, so it starts when it BECOMES true and not while it stays true.
CREATE TABLE routine_sensor_state (
    task_id    INT UNSIGNED NOT NULL,
    matched    TINYINT(1) NOT NULL,
    last_value VARCHAR(40) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (task_id),
    CONSTRAINT fk_rss_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
