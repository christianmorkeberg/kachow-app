-- Web-push notifications (modular): device subscriptions, per-user per-type
-- on/off preferences, a periodic-send log for dedup, and a nudged marker on
-- work sessions. Run once on the server DB (kachowdk_ai).
--
-- user_id is INT UNSIGNED to match users.id.

CREATE TABLE push_subscriptions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    endpoint      TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,               -- sha256(endpoint), for a unique key
    p256dh        VARCHAR(255) NOT NULL,
    auth          VARCHAR(255) NOT NULL,
    ua            VARCHAR(255) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_sent_at  TIMESTAMP NULL,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    CONSTRAINT fk_push_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_push_subs_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (user, notification type) the user has explicitly toggled. Absence
-- means "use the type's default". Modular: new types need no schema change.
CREATE TABLE notification_prefs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type_key   VARCHAR(40) NOT NULL,
    enabled    TINYINT(1) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_pref (user_id, type_key),
    CONSTRAINT fk_notif_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dedup for periodic notifications (e.g. the weekly summary): one row per
-- (user, type, period). period_key is the thing that must be unique per send,
-- e.g. the ISO week-start date "2026-07-06".
CREATE TABLE notification_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type_key   VARCHAR(40) NOT NULL,
    period_key VARCHAR(32) NOT NULL,
    sent_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notif_log (user_id, type_key, period_key),
    CONSTRAINT fk_notif_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marks an open work session already reminded, so the checkout nudge fires once.
ALTER TABLE work_events ADD COLUMN nudged_at DATETIME NULL AFTER note;
