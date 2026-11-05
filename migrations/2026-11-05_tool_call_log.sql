-- One row per tool call, from every entry point (chat, scheduled task, routine, voice, live, offer, export): which tool, how it went and how long it took.
-- NEVER the arguments or results (they can hold personal data). No foreign keys on purpose: the log outlives deleted tasks and conversations. Rows older than ~180 days are pruned.
-- Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-05_tool_call_log.sql
CREATE TABLE tool_call_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    source          VARCHAR(16) NOT NULL,
    task_id         INT UNSIGNED NULL,
    conversation_id INT UNSIGNED NULL,
    tool            VARCHAR(80) NOT NULL,
    ok              TINYINT(1) NOT NULL,
    ms              INT UNSIGNED NOT NULL DEFAULT 0,
    error           VARCHAR(200) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tcl_tool (tool, created_at),
    INDEX idx_tcl_source (source, created_at),
    INDEX idx_tcl_task (task_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
