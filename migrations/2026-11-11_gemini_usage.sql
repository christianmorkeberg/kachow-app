-- One row per Gemini call (chat, scheduled routines, web search, code runs, titles, receipts, the recruiter chat …): which model, for what, and the TOKEN counts the API reports.
-- Cost is not stored: it is worked out when read, from gemini_prices (edited in the Insights card), so a price change re-prices history. No message content, no arguments.
-- Run once on the server DB (kachowdk_ai), AFTER the deploy:  kdb < migrations/2026-11-11_gemini_usage.sql
CREATE TABLE IF NOT EXISTS gemini_usage (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NULL,
    purpose        VARCHAR(40) NOT NULL,
    model          VARCHAR(80) NOT NULL,
    prompt_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    cached_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
    thought_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    tool_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gu_time (created_at),
    INDEX idx_gu_purpose (purpose, created_at),
    INDEX idx_gu_model (model, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- US dollars per million tokens, per model, set by hand (Google's price page) — there is no price in the API's answer.
CREATE TABLE IF NOT EXISTS gemini_prices (
    model        VARCHAR(80) NOT NULL PRIMARY KEY,
    input_per_m  DECIMAL(10,4) NOT NULL DEFAULT 0,
    cached_per_m DECIMAL(10,4) NOT NULL DEFAULT 0,
    output_per_m DECIMAL(10,4) NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
