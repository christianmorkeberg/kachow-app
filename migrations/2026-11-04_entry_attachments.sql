-- Extra files on an income or expense entry (beyond its one invoice/receipt file): a timesheet that backs an invoice, a contract, a statement — any
-- PDF, image, CSV, spreadsheet or document. Stored outside the webroot like the bilag (ReceiptStorage), served through an authenticated endpoint.
-- Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-04_entry_attachments.sql
CREATE TABLE entry_attachments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    entity_type ENUM('income','expense') NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    file_ref    VARCHAR(255) NOT NULL,
    mime        VARCHAR(100) NOT NULL,
    name        VARCHAR(160) NOT NULL,
    size        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attach_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_attach_entry (user_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
