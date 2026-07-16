-- Line-item extraction for receipts: the individual purchased lines read from a
-- photo (description, qty, amount), stored as JSON on the receipt row. Read-only
-- (set once when the photo is parsed); header fields stay the source of truth for
-- totals/VAT. Run once on the server DB (kachowdk_ai).

ALTER TABLE receipts
    ADD COLUMN line_items JSON NULL AFTER note;
