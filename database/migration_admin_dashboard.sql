ALTER TABLE users
    ADD COLUMN account_status ENUM('active','blocked') NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN blocked_at DATETIME NULL AFTER account_status,
    ADD COLUMN last_login_at DATETIME NULL AFTER blocked_at,
    ADD INDEX idx_users_account_status_created (account_status, created_at),
    ADD INDEX idx_users_last_login_at (last_login_at);
