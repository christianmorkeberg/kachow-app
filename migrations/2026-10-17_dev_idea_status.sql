-- Dev ideas get a status: new -> in_progress (taken into the LLM backlog / being built) -> done.
-- Existing ideas start as 'new'. status_at = when the status last changed (UTC), NULL until then.
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE dev_ideas
    ADD COLUMN status ENUM('new','in_progress','done') NOT NULL DEFAULT 'new' AFTER idea,
    ADD COLUMN status_at DATETIME NULL AFTER status;
