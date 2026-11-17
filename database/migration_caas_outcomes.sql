-- Run once on an existing installation. Existing failures have no reliably
-- recorded reason and remain NULL; do not infer balance failures from status.
ALTER TABLE payment_transactions
    ADD COLUMN provider_status_code VARCHAR(32) NULL,
    ADD COLUMN failure_reason VARCHAR(32) NULL;
