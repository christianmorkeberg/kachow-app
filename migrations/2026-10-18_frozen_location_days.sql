-- One row per user per LOCAL day: that day's stays, trips and no-data gaps as StayDetector
-- derived them, saved by bin/location-freeze-cron.php before the raw points age out (60-day
-- retention, location_points). Timeline reads these only for days whose raw points are gone;
-- while raw points exist everything is still computed live, so tuning the location_*
-- settings keeps working. Stored WITHOUT place names (coordinates only): places are matched
-- at read time, so renaming or adding a place still applies to old days.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE frozen_location_days (
    user_id   INT UNSIGNED NOT NULL,
    day       DATE NOT NULL,
    data      MEDIUMTEXT NOT NULL,
    frozen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_frozen_location_days_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
