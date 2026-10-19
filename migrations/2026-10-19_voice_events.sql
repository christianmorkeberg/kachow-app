-- Voice mode (Gemini Live) spike: a log of voice sessions for the developer. One row per event —
-- session start, tool calls (name, ok, latency), interruptions, cancellations, GoAway, idle close, errors,
-- time to first audio. Voice transcripts go into the normal conversation (messages table), not here.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE voice_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    session_id      CHAR(32) NOT NULL,
    conversation_id INT UNSIGNED NULL,
    kind            VARCHAR(24) NOT NULL,
    name            VARCHAR(64) NULL,           -- the tool for tool_call / tool_error
    ok              TINYINT(1) NULL,
    ms              INT UNSIGNED NULL,          -- latency / duration
    detail          TEXT NULL,                  -- JSON
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_time (user_id, created_at),
    KEY idx_session (session_id),
    CONSTRAINT fk_voice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
