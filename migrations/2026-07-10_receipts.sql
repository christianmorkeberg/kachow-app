-- Receipt / expense tracking (photo or dictated). Header-level fields; the image
-- (if any) lives OUTSIDE the webroot, only its filename is stored here.
-- Run once on the server DB (kachowdk_ai).

CREATE TABLE receipts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    source       ENUM('photo','manual') NOT NULL DEFAULT 'manual',
    status       ENUM('draft','confirmed') NOT NULL DEFAULT 'draft',
    file_ref     VARCHAR(255) NULL,             -- filename under uploads/receipts/<userId>/
    mime         VARCHAR(40) NULL,
    vendor       VARCHAR(160) NULL,
    purchased_at DATE NULL,
    total        DECIMAL(10,2) NULL,            -- grand total incl. VAT
    vat          DECIMAL(10,2) NULL,            -- VAT / moms amount
    currency     VARCHAR(3) NOT NULL DEFAULT 'DKK',
    category     VARCHAR(40) NULL,
    note         VARCHAR(255) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_receipts_user_date (user_id, purchased_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
