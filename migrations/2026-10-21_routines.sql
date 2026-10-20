-- Routines: scheduled tasks that can also be triggered by a spoken/typed phrase or by entering/leaving a place,
-- with conditions and an "ask first" mode. A routine IS a scheduled_tasks row (same instruction run, same card,
-- same pause/resume); phrase and place routines have frequency 'event' and are run on request.
-- Run once, before deploying the code that uses it. Additive.

ALTER TABLE scheduled_tasks
    MODIFY frequency ENUM('minutes','hourly','daily','weekdays','weekly','once','event') NOT NULL,
    MODIFY status ENUM('active','paused','cancelled','done','draft') NOT NULL DEFAULT 'active',
    ADD COLUMN trigger_type ENUM('time','phrase','place') NOT NULL DEFAULT 'time' AFTER frequency,
    ADD COLUMN phrases VARCHAR(500) NULL AFTER instruction,
    ADD COLUMN trigger_config TEXT NULL AFTER phrases,
    ADD COLUMN conditions TEXT NULL AFTER trigger_config,
    ADD COLUMN run_mode ENUM('auto','ask') NOT NULL DEFAULT 'auto' AFTER conditions,
    ADD COLUMN run_requested_at DATETIME NULL AFTER next_run_at,
    ADD COLUMN run_trigger VARCHAR(160) NULL AFTER run_requested_at,
    ADD KEY idx_requested (status, run_requested_at);

-- Where the user was relative to each place a place-routine watches, so "left" / "arrived" can be told from one point to the next.
CREATE TABLE routine_place_state (
    task_id    INT UNSIGNED NOT NULL,
    place_id   INT UNSIGNED NOT NULL,
    inside     TINYINT(1) NOT NULL,
    streak     TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- consecutive accurate points that disagree with `inside`
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (task_id, place_id),
    CONSTRAINT fk_rps_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Ask first" routines: a pending question per automatic trigger, answered from the app (card or push).
CREATE TABLE routine_requests (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    trigger_text VARCHAR(160) NOT NULL,
    status       ENUM('pending','approved','declined','expired') NOT NULL DEFAULT 'pending',
    created_at   DATETIME NOT NULL,
    decided_at   DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_user_pending (user_id, status),
    KEY idx_task (task_id),
    CONSTRAINT fk_rr_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
