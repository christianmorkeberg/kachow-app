-- Track when a shared-shopping item was checked off, so checked items can be
-- auto-removed after a period (~24h). Run once on the server DB (kachowdk_ai).

ALTER TABLE shared_list_items
    ADD COLUMN checked_at TIMESTAMP NULL AFTER checked_by;
