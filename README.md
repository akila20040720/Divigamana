# Divigamana.lk

Mobile-first multilingual Sri Lankan astrology application for PHP 8.x and MySQL/MariaDB. It uses no Node.js or server-side build tooling.

## Local/development setup

1. Create a MySQL database using `utf8mb4` and import [database/schema.sql](database/schema.sql), then [database/seed.sql](database/seed.sql) in phpMyAdmin.
2. Copy `config/config.example.php` to `config/local.php`. On cPanel, keep this file one directory above `public_html` if possible and adjust `app/bootstrap.php` to load that path.
3. Set database credentials. `mock_mode => true` makes results visibly mock development responses; it does not call an AI provider.
4. Serve the repository root as the web root, or copy `index.php`, `api.php`, `robots.txt`, `sitemap.xml`, `.htaccess`, and `public/assets/` into `public_html` while keeping `app/` and `config/local.php` above it.
5. Ensure PHP extensions `pdo_mysql`, `curl`, `mbstring`, and `openssl` are enabled. Enforce HTTPS and set `app_env` to `production` before launch.

## Qwen production configuration

Set `qwen.mock_mode` to `false`, then add the Alibaba Cloud Model Studio **production inference** key, authorised regional OpenAI-compatible base URL, and model ID. The current recommended Singapore endpoint is workspace-specific:

```php
'qwen' => ['api_key'=>'YOUR_SERVER_ONLY_KEY','base_url'=>'https://{WORKSPACE_ID}.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1','model'=>'qwen-plus','timeout_seconds'=>45,'max_output_tokens'=>1600,'temperature'=>0.85,'mock_mode'=>false]
```

Never place this key in JavaScript, Git, public files, browser responses, or error logs. The application posts to `/chat/completions` from PHP cURL, records model/tokens/duration, and rejects malformed structured output. Use the endpoint and API key from the same Model Studio region/workspace.

## Current development simulation

- Any six-digit OTP completes only the **demo** verification route (`otp_verified_demo=1`); `phone_verified_real` remains `0`.
- No SMS is sent; no telecom or card billing is activated.
- An active subscription and Rs. 20 service transaction are explicitly `is_simulated=1`.
- Production deployment must replace demo OTP with hashed, expiring provider-issued codes, resend/attempt controls, and ownership verification. Billing stays off until a provider is implemented and tested.

## cPanel deployment checklist

- [ ] Create limited MySQL user and import both SQL files with phpMyAdmin.
- [ ] Store `local.php` outside `public_html`; set correct ownership and restrictive permissions.
- [ ] Set `app_env` to `production`, `mock_mode` to `false`, and production Qwen credentials.
- [ ] Configure HTTPS redirect, PHP 8.1+ and secure session cookies.
- [ ] Configure a daily optional cron to warm Rahu cache after a cache writer is added; current calculation is deterministic on each request using the Colombo configuration. Qwen generates readings; it is not used to guess a time-sensitive Rahu Kalaya window.
- [ ] Create a real admin user (`UPDATE users SET role='admin' WHERE mobile_e164='+94…'`) only after secure demo replacement.
- [ ] Add privacy/deletion workflow, real SMS, billing authorization/idempotency/reconciliation and tested backups before accepting real payments.

## Functional/security test plan

1. Change SI/EN/TA; ensure UI language persists after refresh and no existing result is silently regenerated.
2. Try `0771234567`, accept disclosure, then submit `123456`; confirm demo label and `phone_verified_real=0`.
3. Submit a free reading: user must be authenticated/subscribed and exactly one request/result is saved.
4. Submit a paid reading and cancel before confirmation: no request or transaction exists. Confirm then submit twice with same idempotency key: only one generation/transaction exists.
5. Test malformed CSRF, unauthenticated generation, expired subscription, invalid mobile, blank required questionnaire fields, and cross-user request IDs.
6. In production, test SMS expiry/rate limits, Qwen timeout/rate-limit responses, database least privilege, HTTPS headers, log redaction, and personal-data deletion.

Example test user: register `077 123 4567` with any six digit code; it becomes `+94771234567` and a simulated daily subscription is created.
