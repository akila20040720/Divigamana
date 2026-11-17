-- Preserve the account status stored in users and repair legacy rows that
-- were shown as admin-blocked without an administrator block timestamp.
UPDATE users
SET account_status = 'inactive'
WHERE account_status = 'blocked'
  AND blocked_at IS NULL;
