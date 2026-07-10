-- Chat history: give conversations an (AI-generated) title for the history list.
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE conversations ADD COLUMN title VARCHAR(120) NULL AFTER user_id;
