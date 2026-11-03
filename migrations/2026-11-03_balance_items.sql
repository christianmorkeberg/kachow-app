-- Hand-entered balance-sheet items (status): things Kachow cannot derive from invoices and
-- receipts — equipment, a loan, a stated bank balance. A row is valid from `as_of` until a
-- later row with the same (kind, label) replaces it; amount 0 retires it.
-- Run once on the server DB (kachowdk_ai):  kdb < migrations/2026-11-03_balance_items.sql
CREATE TABLE balance_items (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    kind       ENUM('asset','liability') NOT NULL,
    label      VARCHAR(80) NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    as_of      DATE NOT NULL,
    note       VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bitems_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_bitems (user_id, kind, label, as_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
