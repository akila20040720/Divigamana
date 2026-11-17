-- Add inactive as a provider-independent account state.
ALTER TABLE users
    MODIFY COLUMN account_status ENUM('active','inactive','blocked')
    NOT NULL DEFAULT 'active';

-- Synchronize existing real subscribers without overriding manually blocked users.
UPDATE users u
SET u.account_status = CASE
    WHEN u.account_status = 'blocked' THEN 'blocked'
    WHEN EXISTS (
        SELECT 1
        FROM user_subscriptions s
        WHERE s.user_id = u.id
          AND s.is_simulated = 0
          AND s.status = 'active'
          AND s.id = (
              SELECT MAX(latest.id)
              FROM user_subscriptions latest
              WHERE latest.user_id = s.user_id
                AND latest.provider = s.provider
                AND latest.is_simulated = 0
          )
    ) THEN 'active'
    ELSE 'inactive'
END
WHERE u.account_status <> 'blocked'
  AND (u.ideamart_subscriber_id IS NOT NULL OR u.mspace_subscriber_id IS NOT NULL);
