# Divigamana.lk

Mobile-first multilingual Sri Lankan astrology application for PHP 8.x and MySQL/MariaDB. It uses no Node.js or server-side build tooling.

## Ideamart integration

Ideamart calls are server-side only. Set these environment variables on the host (never in
the browser or a committed config file):

```text
IDEAMART_APP_ID=
IDEAMART_PASSWORD=
```

Copy `.env.example` to the server's private `.env`, fill in the credentials, and set
`IDEAMART_LIVE=true` only after the application is provisioned and its server IP is
whitelisted. Keep `IDEAMART_CAAS_LIVE=false` through OTP/subscription testing; it is a
separate kill switch for real-money debit requests. With either switch or its required
credentials missing, the corresponding operation refuses to make a network call. The demo OTP flow is disabled by default.
Set `demo_otp_enabled` to `true` only for local development. Set `ideamart_live` to `true` only on a server with valid credentials and
the required Ideamart IP whitelist/provisioning.

The database uses TCP by default (`DB_HOST=127.0.0.1`, `DB_PORT=3306`) to avoid PHP
trying a missing local MySQL socket. Set `DB_HOST=localhost` only when the production
host intentionally exposes MySQL through its Unix socket.

For a temporary local outage workaround only, set `SKIP_DATABASE_CHECK=true`. OTP and
Ideamart registration can complete, but the session is temporary and no user, consent,
subscription, or payment record is saved. Set it back to `false` before production use.

The application uses the configured Ideamart OTP request/verify, subscription register and
unregister/status/base, and CAAS balance/direct-debit endpoints. Configure
`https://divigamana.lk/api.php?action=ideamart-subscription-notification` as the IdeaMart
Subscription Notification URL and
`https://divigamana.lk/api.php?action=ideamart-charge-notification` as the Charging
Notification URL, then run
[`database/migration_ideamart_subscription_sync.sql`](database/migration_ideamart_subscription_sync.sql)
once on existing databases. It records carrier subscription state changes so the admin
dashboard reflects current provider details. If IdeaMart provisioned a specific CaaS payment
account, set `IDEAMART_CAAS_ACCOUNT_ID`; otherwise leave it blank. Paid readings use the
server-side `ServiceCatalog` price map; `name-reading` is `0.00` and remains free. Every
paid debit creates and stores its `externalTrxId` before the request and treats `E1379` as
an idempotent success. Run `database/migration_ideamart.sql` on databases created before
the Ideamart integration.

## mSpace Mobitel TAP

The optional mSpace provider implements the provisioned CAAS OTP flow. Mobitel must
supply and approve the exact debit and OTP-verification endpoints; no endpoint is assumed
by default. Credentials are read from `MSPACE_APP_ID` and `MSPACE_PASSWORD`. Enable
`MSPACE_LIVE=true` only after provisioning and IP allow-listing, and keep the independent
`MSPACE_CAAS_LIVE=false` until final billing approval. Use `POST /api.php?action=mspace-charge-otp` followed by
`POST /api.php?action=mspace-charge-verify`.

## Local/development setup

1. Create a MySQL database using `utf8mb4` and import [database/schema.sql](database/schema.sql), then [database/seed.sql](database/seed.sql) in phpMyAdmin.
2. Copy `config/config.example.php` to `config/local.php`. On cPanel, keep this file one directory above `public_html` if possible and adjust `app/bootstrap.php` to load that path.
3. Set database credentials. `mock_mode => true` makes results visibly mock development responses; it does not call an AI provider.
4. Serve the repository root as the web root, or copy `index.php`, `api.php`, `robots.txt`, `sitemap.xml`, `.htaccess`, and `public/assets/` into `public_html` while keeping `app/` and `config/local.php` above it.
5. Ensure PHP extensions `pdo_mysql`, `curl`, `mbstring`, and `openssl` are enabled. Enforce HTTPS and set `app_env` to `production` before launch.

## Admin dashboard

Visit `/admin` to access the operational dashboard with the `ADMIN_USERNAME` and
`ADMIN_PASSWORD` values from the private environment configuration.

Before using the expanded user management dashboard on an existing database, run
[`database/migration_admin_dashboard.sql`](database/migration_admin_dashboard.sql) once
in phpMyAdmin. It adds account blocking and last-login audit fields; new installations
receive them automatically from `database/schema.sql`.

Before enabling either CAAS switch on an existing database, also run
[`database/migration_production_hardening.sql`](database/migration_production_hardening.sql).
It keeps the application's immutable idempotency key separate from the carrier's internal
transaction reference, preventing retries from creating a second charge.

## Production verification

Run the offline route and carrier-contract suite first; it exercises every API route and
both CAAS payload formats without contacting a carrier or moving money:

```bash
php tests/run.php
```

Then run the production preflight on the deployed server:

```bash
php scripts/preflight.php
```

After Ideamart credentials and the production server IP allow-list are confirmed, its
read-only subscriber-base check can be added. This still never calls a debit endpoint:

```bash
php scripts/preflight.php --live-ideamart-readonly
```

Only after every required preflight item passes, limited-production billing is approved,
and a real low-value transaction is supervised should `IDEAMART_CAAS_LIVE` or
`MSPACE_CAAS_LIVE` be changed to `true`.

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
- [ ] Run `migration_admin_dashboard.sql` and `migration_production_hardening.sql` once on an existing database.
- [ ] Change the hardcoded `/admin` credentials before production deployment.
- [ ] Run `php tests/run.php` and `php scripts/preflight.php` on the production host.
- [ ] Keep both CAAS live switches off until carrier approval and supervised limited-production testing.
- [ ] Add privacy/deletion workflow, real SMS, billing authorization/idempotency/reconciliation and tested backups before accepting real payments.

## Functional/security test plan

1. Change SI/EN/TA; ensure UI language persists after refresh and no existing result is silently regenerated.
2. Try `0771234567`, accept disclosure, then submit `123456`; confirm demo label and `phone_verified_real=0`.
3. Submit a free reading: user must be authenticated/subscribed and exactly one request/result is saved.
4. Submit a paid reading and cancel before confirmation: no request or transaction exists. Confirm then submit twice with same idempotency key: only one generation/transaction exists.
5. Test malformed CSRF, unauthenticated generation, expired subscription, invalid mobile, blank required questionnaire fields, and cross-user request IDs.
6. In production, test SMS expiry/rate limits, Qwen timeout/rate-limit responses, database least privilege, HTTPS headers, log redaction, and personal-data deletion.

Example test user: register `077 123 4567` with any six digit code; it becomes `+94771234567` and a simulated daily subscription is created.
