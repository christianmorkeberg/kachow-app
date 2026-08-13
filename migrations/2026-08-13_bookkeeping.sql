-- Bookkeeping phase 1: the income side, owner drawings, an append-only audit log,
-- and udlæg (privately-paid expense) tracking on receipts.
--
-- Design: Kachow is a bookkeeping ASSISTANT (not the legal system of record) but is
-- built to MIMIC bogføringsloven — every entry has a voucher/bilag, booked entries
-- are retained (never hard-deleted), and every create/edit/book is written to
-- bookkeeping_audit (the softer-immutability model: edits allowed, but trailed).
-- Enkeltmandsvirksomhed, moms-registered, quarterly, DK-only (flat 25%).
-- Run once on the server DB (kachowdk_ai).

-- Income: issued invoices (public via NemHandel, or private) and other revenue.
-- issued_at drives the moms period (faktureringsprincippet); paid_at tracks cash.
CREATE TABLE income (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    kind          ENUM('invoice','other') NOT NULL DEFAULT 'invoice',
    source        ENUM('nemhandel','private','manual','photo') NOT NULL DEFAULT 'manual',
    status        ENUM('draft','booked') NOT NULL DEFAULT 'draft',
    doc_number    VARCHAR(40) NULL,              -- invoice/voucher number (external, or Kachow's K-YEAR-NNN series)
    customer      VARCHAR(160) NULL,
    issued_at     DATE NULL,                     -- invoice/delivery date — drives salgsmoms period
    paid_at       DATE NULL,                     -- when the money arrived; NULL = outstanding (debitor)
    amount_ex_vat DECIMAL(10,2) NULL,            -- net, excluding VAT
    vat           DECIMAL(10,2) NULL,            -- output VAT (salgsmoms)
    total         DECIMAL(10,2) NULL,            -- gross, incl. VAT
    currency      VARCHAR(3) NOT NULL DEFAULT 'DKK',
    category      VARCHAR(60) NULL,
    note          VARCHAR(255) NULL,
    file_ref      VARCHAR(255) NULL,             -- bilag filename under uploads/receipts/<userId>/ (reuses ReceiptStorage)
    mime          VARCHAR(40) NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_income_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_income_user_date (user_id, issued_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Owner drawings (privat hævning): money the owner pays themselves out of the
-- business. For an enkeltmandsvirksomhed this is NOT a salary/expense and does NOT
-- affect profit or moms — it is a pure equity/cash movement, tracked here so the
-- reserve/overview can show real free cash.
CREATE TABLE owner_draws (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    drawn_at   DATE NOT NULL,
    amount     DECIMAL(10,2) NOT NULL,
    currency   VARCHAR(3) NOT NULL DEFAULT 'DKK',
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_draws_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_draws_user_date (user_id, drawn_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only audit trail: one row per create/update/book/paid/reimburse/delete on
-- any bookkeeping entity. This is the kontrolspor — it is never edited or deleted.
CREATE TABLE bookkeeping_audit (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    entity_type ENUM('income','expense','draw') NOT NULL,
    entity_id   INT UNSIGNED NOT NULL,
    action      VARCHAR(32) NOT NULL,           -- create | update | book | paid | reimburse | delete
    detail      JSON NULL,                      -- changed fields / snapshot for the trail
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_audit_entity (user_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Udlæg on the expense side: an expense the owner paid from PRIVATE funds. It stays
-- a normal deductible business expense (still counts in P&L + input VAT); these
-- columns only track that it was privately paid and whether the company has paid the
-- owner back yet (so "udlæg owed to you" can be surfaced).
ALTER TABLE receipts
    ADD COLUMN paid_privately TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN reimbursed_at  DATE NULL;
