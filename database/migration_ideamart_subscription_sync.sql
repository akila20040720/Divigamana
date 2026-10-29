-- Run once on existing installations before configuring the IdeaMart callback.
-- It preserves existing local subscription records and adds provider callback details.
ALTER TABLE user_subscriptions
    MODIFY COLUMN status ENUM('active','inactive','cancelled','expired','pending') NOT NULL,
    ADD COLUMN provider ENUM('simulated','ideamart','mspace') NOT NULL DEFAULT 'simulated' AFTER is_simulated,
    ADD COLUMN provider_status VARCHAR(30) NULL AFTER provider,
    ADD COLUMN frequency VARCHAR(60) NULL AFTER provider_status,
    ADD COLUMN notified_at VARCHAR(40) NULL AFTER ends_at,
    ADD INDEX idx_subscriptions_provider_status (provider, provider_status);

UPDATE user_subscriptions us
JOIN users u ON u.id=us.user_id
SET us.provider='ideamart', us.provider_status=IF(us.status='active','REGISTERED','UNREGISTERED')
WHERE us.is_simulated=0 AND u.ideamart_subscriber_id IS NOT NULL;
