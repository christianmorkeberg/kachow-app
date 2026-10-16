-- Caches a best-effort POI name (e.g. "Netto") looked up near an unnamed place the user
-- keeps returning to (dev idea #38), keyed by a coarse (~11m) lat/lon cell so repeat card
-- opens don't re-query the lookup service — required by Nominatim's usage policy, and
-- there's no reason to ask twice for the same spot anyway. No expiry: a cell is only ever
-- consulted again while the user hasn't yet named (saved) that place, so re-checking
-- whether a shop renamed in the meantime buys nothing. Run once on the server DB
-- (kachowdk_ai). No FK — not tied to any one user; a POI's name doesn't depend on who
-- visited it.

CREATE TABLE poi_name_cache (
    lat_key    DECIMAL(7,4) NOT NULL,
    lon_key    DECIMAL(7,4) NOT NULL,
    name       VARCHAR(120) NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lat_key, lon_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
