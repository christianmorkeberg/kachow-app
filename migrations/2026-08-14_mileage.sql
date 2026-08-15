-- Driving / mileage tracking (kørsel) for the bookkeeping cockpit. Each row is one
-- driving DAY to the (single) customer workplace; km defaults to the stored round-trip
-- distance. A rolling 60-work-day counter classifies each day: the first 60 days in a
-- trailing 12 months are erhvervsmæssig kørsel (business, statens takst → a business
-- deduction that lowers profit + the tax reserve); day 61+ becomes commuting to a fast
-- arbejdssted → befordringsfradrag (a PERSONAL deduction, estimated separately, never in
-- the business P&L). No moms, no cash movement. Rates/distance live in user_settings.
-- Run once on the server DB (kachowdk_ai).
CREATE TABLE mileage_trips (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    trip_date  DATE NOT NULL,
    km         DECIMAL(8,2) NOT NULL,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mileage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_mileage_user_date (user_id, trip_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
