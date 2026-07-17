-- Per-user AI-generated conversation-starter chips for the empty chat screen.
-- Regenerated once a day by bin/quick-actions-cron.php from the user's recent
-- messages (so they stay fresh + in the user's own language). One row per user;
-- the endpoint falls back to frequent/default chips when a row is missing/stale.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE quick_action_cache (
    user_id      INT UNSIGNED PRIMARY KEY,
    suggestions  JSON NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_qac_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
