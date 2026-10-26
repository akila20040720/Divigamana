-- Run once on an existing Divigamana database before using the expanded /admin dashboard.
-- This adds admin-controlled blocking and customer-login audit fields; it does not delete
-- or alter existing customer, subscription, payment, or reading data.
ALTER TABLE users
    ADD COLUMN account_status ENUM('active','blocked') NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN blocked_at DATETIME NULL AFTER account_status,
    ADD COLUMN last_login_at DATETIME NULL AFTER blocked_at,
    ADD INDEX idx_users_account_status_created (account_status, created_at),
    ADD INDEX idx_users_last_login_at (last_login_at);
