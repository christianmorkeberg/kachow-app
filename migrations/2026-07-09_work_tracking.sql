-- Work-time tracking (option A: iOS geofence -> URL -> DB insert).
-- Run once on the server DB (kachowdk_ai).
--
--   api_tokens : per-user bearer tokens for URL-triggered automations (no session).
--   work_events: append-only in/out punches; sessions are derived at read time.
--
-- user_id is INT UNSIGNED to match users.id (errno-150 lesson).

CREATE TABLE api_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    scope        VARCHAR(32) NOT NULL,
    token        VARCHAR(64) NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    UNIQUE KEY uq_api_tokens_token (token),
    UNIQUE KEY uq_api_tokens_user_scope (user_id, scope),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE work_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    kind        ENUM('in','out') NOT NULL,
    occurred_at DATETIME NOT NULL,             -- stored in UTC
    source      VARCHAR(32) NOT NULL DEFAULT 'ios_geofence',
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_work_events_user_time (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
