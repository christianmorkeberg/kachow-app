-- Private-invoice generation (bookkeeping phase 4): Kachow generates a compliant DK
-- invoice for a private client (public bodies still go via NemHandel and are only
-- recorded). Adds line items + a due date to the income row; the invoice document
-- itself is rendered on demand from this data (api/invoice-view.php), so no file is
-- stored. Generated invoices use Kachow's own gapless K-YEAR-NNN number series.
-- The seller's company details (name/CVR/address/payment) live in user_settings.
-- Run once on the server DB (kachowdk_ai).
ALTER TABLE income
    ADD COLUMN line_items JSON NULL,   -- [{description, qty, unit_price, amount}] for generated invoices
    ADD COLUMN due_at     DATE NULL;   -- payment due date shown on the invoice
