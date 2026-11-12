# Divigamana.lk

Mobile-first multilingual Sri Lankan astrology application for PHP 8.x and MySQL/MariaDB. It uses no Node.js or server-side build tooling.

## OTP sign-in and one-time CAAS payments

Mobile sign-in uses the IdeaMart registration OTP integration. The subscription and
unsubscribe actions use the IdeaMart Subscription API. Each paid reading is separately
charged once for LKR 20.00 through the server-side IdeaMart CAAS direct-debit API.

IdeaMart CAAS requires the server IP to be allow-listed and the debit service to be
provisioned. The UI calls `POST /api.php?action=caas-charge`; the server persists the
external transaction ID before charging and treats `E1379` as an idempotent success.

IdeaMart server credentials are still needed for the registration and subscription APIs;
never expose them in browser code or committed configuration. Paid prices are enforced by
the server-side `ServiceCatalog` (LKR 20.00 per paid reading; `name-reading` is free).
The payment record is created before the debit request, and a confirmed payment can be
claimed by one reading only. Existing carrier subscriptions created outside this
application are not automatically cancelled.

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

For existing databases, also run [`database/migration_dashboard_analytics.sql`](database/migration_dashboard_analytics.sql)
once to add indexes for paginated user cohorts and date-scoped carrier payment and
subscription reports. New installations receive these indexes from `database/schema.sql`.

Before enabling either CAAS switch on an existing database, also run
[`database/migration_production_hardening.sql`](database/migration_production_hardening.sql).
It keeps the application's immutable idempotency key separate from the carrier's internal
transaction reference, preventing retries from creating a second charge.

## Billing verification

Run the offline route and carrier-contract suite. It does not contact a carrier or move
money:

```bash
php tests/run.php
```

Use `php scripts/preflight.php` to check production configuration. Only enable live Mobitel
CAAS after the provider endpoints, credentials, and IP allow-list have been approved.

The mSpace CAAS integration uses the documented endpoints
`https://api.mspace.lk/caas/direct/debit` (OTP generation) and
`https://api.mspace.lk/caas/otp/verify` (OTP verification). The generation response's
`requestCorrelator` is sent back as `referenceNo` during verification. Keep
`MSPACE_LIVE` and `MSPACE_CAAS_LIVE` disabled until Mobitel has provisioned the
application and allow-listed the production server IP.

## Qwen production configuration

Set `qwen.mock_mode` to `false`, then add the Alibaba Cloud Model Studio **production inference** key, authorised regional OpenAI-compatible base URL, and model ID. The current recommended Singapore endpoint is workspace-specific:

```php
'qwen' => ['api_key'=>'YOUR_SERVER_ONLY_KEY','base_url'=>'https://{WORKSPACE_ID}.ap-southeast-1.maas.aliyuncs.com/compatible-mode/v1','model'=>'qwen-plus','timeout_seconds'=>45,'max_output_tokens'=>1600,'temperature'=>0.85,'mock_mode'=>false]
```

Never place this key in JavaScript, Git, public files, browser responses, or error logs. The application posts to `/chat/completions` from PHP cURL, records model/tokens/duration, and rejects malformed structured output. Use the endpoint and API key from the same Model Studio region/workspace.

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
