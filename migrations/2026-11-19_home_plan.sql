-- The floor plan for the Home card: the (private) image and the tappable areas. Stored in the DB, NOT in the public repos: the apartment's layout is personal.
-- One per household: keyed by the user whose home agent it is (HomeOwner owner_id). Run AFTER the deploy: kdb < migrations/2026-11-19_home_plan.sql
CREATE TABLE home_plans (
    user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    mime       VARCHAR(40) NOT NULL DEFAULT 'image/jpeg',
    image      MEDIUMBLOB NULL,
    config     MEDIUMTEXT NULL,                    -- JSON {w, h, areas:[{key,label,match:[…],polygons:[[[x,y],…]]}]}
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_home_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
