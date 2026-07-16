-- General per-user settings: a small key/value store for structured preferences the
-- app/cron reads (distinct from user_instructions, which is free text for the model).
-- Keys are a controlled set defined in App\Data\UserSettings::DEFS. First use:
-- work_calendar (per-user name of the Google calendar for work-log tracking). Run once
-- on the server DB (kachowdk_ai).

CREATE TABLE user_settings (
    user_id       INT UNSIGNED NOT NULL,
    setting_key   VARCHAR(48)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, setting_key),
    CONSTRAINT fk_usettings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
