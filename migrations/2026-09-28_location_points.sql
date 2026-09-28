-- Location tracking, phase 1: raw positions reported by OwnTracks (HTTP mode) to
-- api/owntracks.php. Private per user. Raw points are purged after 60 days
-- (LocationPoints::RETENTION_DAYS); later phases derive stays/trips from them.
-- Auth reuses api_tokens (scope 'location'), so no other new table is needed.
--
-- user_id is INT UNSIGNED to match users.id (errno-150 lesson).

CREATE TABLE location_points (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    device      VARCHAR(32) NOT NULL DEFAULT '',  -- OwnTracks device id (X-Limit-D / topic)
    recorded_at DATETIME NOT NULL,                -- when the phone took the fix (tst), UTC
    lat         DECIMAL(9,6) NOT NULL,
    lon         DECIMAL(9,6) NOT NULL,
    acc         SMALLINT UNSIGNED NULL,           -- horizontal accuracy, metres
    alt         SMALLINT NULL,                    -- metres
    vel         SMALLINT UNSIGNED NULL,           -- km/h as reported
    cog         SMALLINT UNSIGNED NULL,           -- course over ground, degrees
    batt        TINYINT UNSIGNED NULL,            -- battery %
    conn        CHAR(1) NULL,                     -- w = wifi, m = mobile, o = offline
    trig        CHAR(1) NULL,                     -- OwnTracks trigger (p ping, c region, u manual, t timer, v move…)
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_location_points_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_location_points_fix (user_id, device, recorded_at),
    INDEX idx_location_points_user_time (user_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
