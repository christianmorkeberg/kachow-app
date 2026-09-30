-- Kørebog trip suggestions (location tracking phase 5). Two changes:
--
-- 1. Link a mileage destination to a saved place (places), so a detected drive to that place
--    resolves to the destination it should be logged against (its distance + 60-day counter).
--    Nullable — a destination can still exist without a place (manual-only), and ON DELETE SET
--    NULL keeps the destination if the place is removed.
--
-- 2. Remember dismissed suggestions ("not a business drive") so a rejected (day, place) is never
--    re-suggested. Confirmed drives already live in mileage_trips; suggestions are derived on the
--    fly from location, so only the dismissals need storing.
--
-- Private per user. user_id / place_id are INT UNSIGNED to match (errno-150 lesson).
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE mileage_destinations
    ADD COLUMN place_id INT UNSIGNED NULL AFTER type,
    ADD INDEX idx_mdest_place (place_id),
    ADD CONSTRAINT fk_mdest_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE SET NULL;

CREATE TABLE mileage_trip_dismissals (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    trip_date  DATE NOT NULL,
    place_id   INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mdismiss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mdismiss_place FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mdismiss (user_id, trip_date, place_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
