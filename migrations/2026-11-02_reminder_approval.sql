-- Reminders now stay open after they fire until the user approves (ticks) them. A fired reminder is "open" while
-- approved_at is NULL; the app icon badge counts the open ones. Run once on the server DB, BEFORE deploying the code.
-- Everything that already fired is marked approved, so old reminders don't suddenly show up as open.

ALTER TABLE reminders
    ADD COLUMN approved_at DATETIME NULL AFTER sent_at,
    ADD KEY idx_open (user_id, approved_at);

UPDATE reminders SET approved_at = COALESCE(sent_at, UTC_TIMESTAMP()) WHERE status = 'sent';
