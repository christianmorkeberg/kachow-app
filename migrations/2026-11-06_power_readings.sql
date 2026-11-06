-- Power readings from metering smart plugs (Tuya cloud), one row per plug per poll (every 5 minutes, bin/power-cron.php).
-- Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-06_power_readings.sql
CREATE TABLE power_readings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    device_id   VARCHAR(64) NOT NULL,
    device_name VARCHAR(120) NOT NULL,
    online      TINYINT(1) NOT NULL DEFAULT 1,
    switch_on   TINYINT(1) NULL,
    watts       DECIMAL(8,1) NULL,
    volts       DECIMAL(6,1) NULL,
    amps        DECIMAL(7,3) NULL,
    kwh_total   DECIMAL(12,3) NULL,
    read_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_dev (user_id, device_id, read_at),
    INDEX idx_pr_time (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
