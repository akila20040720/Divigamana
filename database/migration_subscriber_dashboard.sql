-- Optional performance index for the rolling 24-hour subscription report.
-- Run once on existing installations; this does not alter or delete records.
ALTER TABLE user_subscriptions ADD INDEX idx_subscriptions_accepted (accepted_at);
