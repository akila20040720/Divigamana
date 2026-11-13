-- Run once before enabling the mSpace OTP/CaaS flow.
ALTER TABLE users ADD COLUMN mspace_subscriber_id VARCHAR(120) NULL UNIQUE AFTER ideamart_subscriber_id;
