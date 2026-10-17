-- Location-triggered reminders ("remind me next time I'm at <place>"): a reminder row with no
-- time (remind_at NULL) that fires when location tracking sees the user arrive at a saved place
-- (place_id) or at any place of a type (place_type, e.g. 'work'). place_label is what the user
-- called it, for listing. place_armed = 0 when the reminder was set while already AT the place:
-- it arms once the user has left, so "next time" means the next visit, not right now.
-- Fired from api/owntracks.php as positions arrive; delivered like any reminder.
-- Run once on the server DB (kachowdk_ai).

ALTER TABLE reminders
    MODIFY remind_at DATETIME NULL,
    ADD COLUMN place_id INT UNSIGNED NULL AFTER remind_at,
    ADD COLUMN place_type VARCHAR(16) NULL AFTER place_id,
    ADD COLUMN place_label VARCHAR(80) NULL AFTER place_type,
    ADD COLUMN place_armed TINYINT(1) NOT NULL DEFAULT 1 AFTER place_label,
    ADD KEY idx_user_pending (user_id, status);
