-- Task offers (report #69). Additive; run once on kachowdk_ai BEFORE deploying the code that uses it (without the table the notification still goes out, just without the Run button).
--
-- A scheduled task that ASKS ("lights are on — turn them off?") can attach the exact tool calls to run on a yes (notify_me's `offer`). The push opens a
-- Run / Skip sheet; Run executes the stored calls (house tools only — see App\Data\TaskOffers::TOOLS) without another chat turn.

CREATE TABLE task_offers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    conversation_id INT UNSIGNED NULL,
    task_id         INT UNSIGNED NULL,
    question        VARCHAR(300) NOT NULL,
    calls           MEDIUMTEXT NOT NULL,                       -- JSON [{tool, args}]
    status          VARCHAR(12) NOT NULL DEFAULT 'pending',    -- pending | running | done | failed | skipped | superseded
    result          TEXT NULL,
    created_at      DATETIME NOT NULL,                         -- UTC
    answered_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_to_user_status (user_id, status),
    CONSTRAINT fk_to_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
