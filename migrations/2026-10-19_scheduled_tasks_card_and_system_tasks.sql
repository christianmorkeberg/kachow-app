-- (1) The scheduled-tasks card: tasks can be PAUSED (deactivated and resumed), and each run leaves
--     what it came to — the run's closing words, ok/error and whether it notified — for the card.
-- (2) System tasks: the built-in cron jobs (notifications, reminders, quick actions, home sync, memory
--     cleanup, location freeze) get a row each — visible in the card and manageable from the assistant:
--     pause/resume, schedule, who triggers them (cPanel as today, or the per-minute dispatcher), and the last
--     run's result. Rows are created by the code on first use; nothing changes until you ask the assistant.
-- Additive, so safe to run BEFORE the new code is pushed. Run once on the server DB (kachowdk_ai).

ALTER TABLE scheduled_tasks
    MODIFY status ENUM('active','paused','cancelled','done') NOT NULL DEFAULT 'active',
    ADD COLUMN last_summary TEXT NULL AFTER last_result_title,
    ADD COLUMN last_run_status ENUM('ok','error') NULL AFTER last_summary,
    ADD COLUMN last_run_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER last_run_status;

CREATE TABLE system_tasks (
    task_key         VARCHAR(40)  NOT NULL,
    status           ENUM('active','paused') NOT NULL DEFAULT 'active',
    -- cpanel: the server's cron entry starts the script, as before. dispatcher: the per-minute
    -- scheduled-tasks cron starts it on the schedule below (and a leftover cPanel entry becomes a no-op).
    mode             ENUM('cpanel','dispatcher') NOT NULL DEFAULT 'cpanel',
    frequency        ENUM('minutes','daily','weekdays','weekly') NOT NULL,
    time_of_day      TIME NULL,
    day_of_week      TINYINT UNSIGNED NULL,
    interval_minutes SMALLINT UNSIGNED NULL,
    next_run_at      DATETIME NULL,                       -- UTC; only used in dispatcher mode
    last_run_at      DATETIME NULL,                       -- UTC
    last_status      ENUM('ok','error','skipped') NULL,
    last_duration_ms INT UNSIGNED NULL,
    last_output      TEXT NULL,
    PRIMARY KEY (task_key),
    KEY idx_due (mode, status, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
