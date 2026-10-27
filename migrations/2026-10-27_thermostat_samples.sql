-- Thermostat probe: one row per valve per minute while the "Thermostat probe" system task is switched on (it is paused by default),
-- to find out how often the Shelly valves really report a temperature — i.e. whether an open window can be detected from them.
-- Rows older than 14 days are deleted by the probe itself. Additive; run once before deploying the code that uses it.

CREATE TABLE thermostat_samples (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    thermostat_id SMALLINT UNSIGNED NOT NULL,        -- the valve's id on the gateway (200, 201 …)
    name          VARCHAR(60) NOT NULL,
    sampled_at    DATETIME NOT NULL,                 -- UTC, when WE read it
    reported_at   DATETIME NULL,                     -- UTC, when the gateway last HEARD the valve (its own timestamp)
    current_c     DECIMAL(4,1) NULL,
    target_c      DECIMAL(4,1) NULL,
    valve_pct     TINYINT UNSIGNED NULL,
    battery_pct   TINYINT UNSIGNED NULL,
    rssi          SMALLINT NULL,
    read_ms       INT UNSIGNED NULL,                 -- how long the read took through the Pico
    error         VARCHAR(200) NULL,
    PRIMARY KEY (id),
    KEY idx_user_valve_time (user_id, thermostat_id, sampled_at),
    CONSTRAINT fk_ts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
