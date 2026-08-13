-- Cash / liquidity tracking: manual bank-account movements that aren't already
-- captured as invoices, expenses or owner draws — e.g. a moms payment to SKAT, a
-- bank fee, or money the owner injects. Together with paid invoices (cash in),
-- business-paid expenses + reimbursed udlæg + owner draws (cash out), these give the
-- EXPECTED bank balance ("how much should be in my account").
--
-- This is a cash-reconciliation aid, not part of the legal bogføring, so entries are
-- freely editable/deletable and are NOT written to bookkeeping_audit.
-- Run once on the server DB (kachowdk_ai).
CREATE TABLE cash_entries (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    occurred_at DATE NOT NULL,
    direction   ENUM('in','out') NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,          -- always positive; direction gives the sign
    category    VARCHAR(24) NOT NULL DEFAULT 'other',  -- moms | tax | fee | deposit | other
    note        VARCHAR(255) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cash_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_cash_user_date (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
