-- Location tracking, phase 2: the user's own named places, drawn in the app (never
-- predefined in code). A place is a circle (centre + radius) or a polygon. Its type
-- decides what it will drive later: work → the automatic work clock (phase 4);
-- business / commute → kørebog trip suggestions (phase 5); home / private / other →
-- timeline only. Private per user, like location_points.
--
-- user_id is INT UNSIGNED to match users.id (errno-150 lesson).

CREATE TABLE places (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(64) NOT NULL,
    type       VARCHAR(16) NOT NULL DEFAULT 'other',  -- work | business | commute | home | private | other
    shape      VARCHAR(8) NOT NULL DEFAULT 'circle',  -- circle | polygon
    lat        DECIMAL(9,6) NOT NULL,                  -- circle centre (polygon: its centroid, for display)
    lon        DECIMAL(9,6) NOT NULL,
    radius_m   SMALLINT UNSIGNED NULL,                 -- circle only
    polygon    TEXT NULL,                              -- polygon only: JSON [[lat, lon], …]
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_places_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_places_user_name (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
