-- Tool targeting, SHADOW mode: nothing changes for the user — every call still sends the whole tool list. Each wide turn records which tools the model called and
-- whether a TARGETED list (the message's areas + the user's most used tools + new tools + a small core) would have had them, and how big that list would have been.
-- Run once on the server DB (kachowdk_ai), AFTER the deploy:  kdb < migrations/2026-11-11_tool_shadow.sql
CREATE TABLE IF NOT EXISTS tool_first_seen (
    tool       VARCHAR(80) NOT NULL PRIMARY KEY,
    first_seen TIMESTAMP NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tool_shadow_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    total_chars INT UNSIGNED NOT NULL,          -- the full tool list sent today
    sizes       VARCHAR(200) NOT NULL,          -- JSON {"10": chars, "20": …}: the targeted list for each top-N size
    called      TEXT NOT NULL,                  -- JSON [{tool, rank, matched, core, new}] for each tool the model called
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tsl_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
