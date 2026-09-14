-- Mileage: multiple driving destinations (kørsel per workplace/customer).
--
-- Until now mileage assumed ONE customer: one round-trip distance, one 60-day
-- counter across all trips, all business driving folded into the P&L. Christian now
-- drives to more than one place (e.g. a Kachow Consult customer AND DTU), and the
-- tax treatment differs per place:
--   type='business' → statens takst, the 60-day rule applies PER destination, and the
--                      business part is a deduction in the business P&L (erhvervskørsel).
--   type='commute'  → befordringsfradrag from day 1 (a fixed/regular workplace such as
--                      DTU); a PERSONAL-return figure, never in the business P&L.
-- The 20,000 km/year high-rate tier stays global (per person per year), not per place.
--
-- home_address / dest_address let the app auto-fill round_trip_km via a routing API
-- (OpenRouteService); they are optional — manual km entry still works.
--
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE mileage_destinations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    type          ENUM('business','commute') NOT NULL DEFAULT 'business',
    round_trip_km DECIMAL(8,2) NOT NULL DEFAULT 0,
    home_address  VARCHAR(255) NULL,
    dest_address  VARCHAR(255) NULL,
    archived_at   TIMESTAMP NULL DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mdest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mdest_user_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mileage_trips
    ADD COLUMN destination_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_mileage_dest (destination_id),
    ADD CONSTRAINT fk_mileage_dest FOREIGN KEY (destination_id)
        REFERENCES mileage_destinations(id) ON DELETE SET NULL;

-- Backfill: give every user who already has driving history a default BUSINESS
-- destination ("Kachow Consult"), seeded from their stored round-trip distance, and
-- attribute all their existing trips to it. Nothing logged is lost or reclassified.
INSERT INTO mileage_destinations (user_id, name, type, round_trip_km)
SELECT u.id, 'Kachow Consult', 'business',
       COALESCE((SELECT CAST(s.setting_value AS DECIMAL(8,2)) FROM user_settings s
                 WHERE s.user_id = u.id AND s.setting_key = 'mileage_round_trip_km'), 0)
FROM users u
WHERE EXISTS (SELECT 1 FROM mileage_trips t WHERE t.user_id = u.id);

UPDATE mileage_trips t
JOIN mileage_destinations d ON d.user_id = t.user_id AND d.name = 'Kachow Consult'
SET t.destination_id = d.id
WHERE t.destination_id IS NULL;
