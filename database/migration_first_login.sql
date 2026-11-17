-- Existing accounts have no recorded first-login timestamp; leave them NULL.
ALTER TABLE users ADD COLUMN first_login_at DATETIME NULL AFTER blocked_at;
