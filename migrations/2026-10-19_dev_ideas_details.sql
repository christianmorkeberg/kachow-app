-- Dev ideas become the app's live backlog: a longer `details` note per idea (design, decisions, what is
-- open) and a priority (high ideas are listed first). Existing ideas keep no details, priority 'normal'.
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE dev_ideas
    ADD COLUMN details TEXT NULL AFTER idea,
    ADD COLUMN priority ENUM('normal','high') NOT NULL DEFAULT 'normal' AFTER status_at;
