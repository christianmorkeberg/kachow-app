-- Saved name -> id list for home devices (Hue lights, rooms, zones, scenes), so the assistant can
-- act on "turn off Le Klint" in ONE call instead of listing everything first (report #36 follow-up).
-- Refreshed daily by bin/home-sync-cron.php, on demand (refresh_home_devices), and for free whenever
-- the assistant lists a resource. Run once on the server DB (kachowdk_ai).

CREATE TABLE home_devices (
    user_id    INT UNSIGNED NOT NULL,
    device     VARCHAR(32)  NOT NULL,
    kind       VARCHAR(16)  NOT NULL,
    rid        VARCHAR(64)  NOT NULL,
    name       VARCHAR(64)  NOT NULL,
    grouped_id VARCHAR(64)  NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, device, rid),
    INDEX idx_home_devices_kind (user_id, device, kind),
    CONSTRAINT fk_home_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
