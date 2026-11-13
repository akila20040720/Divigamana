ALTER TABLE users ADD INDEX idx_users_created_id (created_at, id);

ALTER TABLE user_subscriptions
    ADD INDEX idx_subscriptions_provider_accepted (provider, is_simulated, accepted_at),
    ADD INDEX idx_subscriptions_user_latest (user_id, id);

ALTER TABLE payment_transactions
    ADD INDEX idx_payments_real_created (provider, is_simulated, created_at, status);
