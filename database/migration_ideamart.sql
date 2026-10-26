-- Run once on an existing installation before enabling Ideamart production mode.
ALTER TABLE users ADD COLUMN ideamart_subscriber_id VARCHAR(120) NULL UNIQUE;
