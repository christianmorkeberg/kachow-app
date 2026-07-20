-- Per-message diagnostics + user "report to developer" feedback + a small global
-- flags table (used to toggle expensive diagnostics like model "thoughts" capture
-- without a redeploy). Run once on the server DB (kachowdk_ai).

-- 1) Diagnostics attached to an assistant turn (routing groups, tool calls, model,
--    optional thought summaries). JSON, nullable — only assistant messages carry it.
ALTER TABLE messages ADD COLUMN diagnostics JSON NULL AFTER card;

-- 2) Global on/off flags (key/value). First use: diag_thoughts (capture Gemini
--    thought summaries into diagnostics). Admin-togglable in chat.
CREATE TABLE app_flags (
    flag_key   VARCHAR(48) NOT NULL,
    flag_value VARCHAR(16) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Start with thought capture ON (wanted while bootstrapping the feedback flow).
-- Turn off any time in chat: "turn off thought logging" (admin only).
INSERT INTO app_flags (flag_key, flag_value) VALUES ('diag_thoughts', 'on');

-- 3) Feedback reports a user sends to the developer about a specific message. A JSON
--    snapshot (the message + a little context + its diagnostics) is stored so the
--    report survives even if the conversation is later deleted. conversation_id /
--    message_id are soft references (no FK) for that reason.
CREATE TABLE feedback_reports (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED    NOT NULL,
    conversation_id INT UNSIGNED    NULL,
    message_id      BIGINT UNSIGNED NULL,
    note            TEXT            NULL,
    snapshot        JSON            NOT NULL,
    status          ENUM('new','seen','resolved') NOT NULL DEFAULT 'new',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status, id),
    CONSTRAINT fk_fbr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
