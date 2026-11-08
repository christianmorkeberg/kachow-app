-- The recruiter chat ("Kachow, the owner's assistant" at /meet/<token>): the facts about the owner with a visibility each, one link per application, the chat log (kept
-- 14 days) and the messages recruiters leave. Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-08_recruiter_chat.sql
-- Nothing in here is shown anywhere until a link is created.

-- The catalogue of facts. visibility: public = the assistant may say the body; summary = it may only say the short `summary` (never the body); private = never leaves this table.
CREATE TABLE about_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    category    VARCHAR(40) NOT NULL,
    title       VARCHAR(160) NOT NULL,
    body        MEDIUMTEXT NOT NULL,
    summary     TEXT NULL,
    visibility  ENUM('public','summary','private') NOT NULL DEFAULT 'private',
    lang        CHAR(2) NOT NULL DEFAULT 'en',
    sort        INT NOT NULL DEFAULT 0,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_about_user (user_id, category, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per application. The token IS the access (unguessable, 40 characters). The caps are per link and editable.
CREATE TABLE recruiter_links (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token           VARCHAR(64) NOT NULL,
    label           VARCHAR(160) NOT NULL,
    company         VARCHAR(160) NULL,
    role_title      VARCHAR(160) NULL,
    job_posting     MEDIUMTEXT NULL,
    match_summary   TEXT NULL,
    status          ENUM('active','revoked') NOT NULL DEFAULT 'active',
    expires_at      DATETIME NULL,
    message_cap     INT UNSIGNED NOT NULL DEFAULT 30,
    token_cap       INT UNSIGNED NOT NULL DEFAULT 200000,
    messages_used   INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_used     INT UNSIGNED NOT NULL DEFAULT 0,
    open_count      INT UNSIGNED NOT NULL DEFAULT 0,
    first_opened_at DATETIME NULL,
    last_opened_at  DATETIME NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recruiter_token (token),
    INDEX idx_recruiter_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The chat log. Purged after 14 days (bin/recruiter-purge-cron.php). ip_hash = a salted hash, only for the per-IP rate limit.
CREATE TABLE recruiter_messages (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id    INT UNSIGNED NOT NULL,
    role       ENUM('user','assistant') NOT NULL,
    content    TEXT NOT NULL,
    tokens     INT UNSIGNED NOT NULL DEFAULT 0,
    ip_hash    CHAR(16) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rm_link (link_id, id),
    INDEX idx_rm_ip (ip_hash, created_at),
    CONSTRAINT fk_rm_link FOREIGN KEY (link_id) REFERENCES recruiter_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messages left for the owner: saved FIRST, then pushed and emailed; the two flags say whether those went out. Kept until deleted.
CREATE TABLE recruiter_leads (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(120) NOT NULL,
    contact    VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    push_ok    TINYINT(1) NULL,
    email_ok   TINYINT(1) NULL,
    read_at    DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rl_link (link_id, id),
    CONSTRAINT fk_rl_link FOREIGN KEY (link_id) REFERENCES recruiter_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
