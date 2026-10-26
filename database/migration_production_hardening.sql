-- Run once before enabling IDEAMART_CAAS_LIVE or MSPACE_CAAS_LIVE.
-- The external idempotency key and the provider's internal reference must remain separate.
ALTER TABLE payment_transactions
    ADD COLUMN provider ENUM('simulated','ideamart','mspace') NOT NULL DEFAULT 'simulated' AFTER is_simulated,
    ADD COLUMN external_reference VARCHAR(120) NULL AFTER provider;

UPDATE payment_transactions
SET provider = IF(is_simulated=1,'simulated','ideamart'),
    external_reference = CASE WHEN status='authorized' THEN provider_reference ELSE NULL END;

ALTER TABLE payment_transactions
    ADD UNIQUE INDEX uq_payment_provider_external (provider, external_reference);
