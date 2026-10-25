# HX UPI Gateway

Laravel payment gateway implementing the server and web portions of `prd2.0.md`.
Android development is deferred. **This is not yet a production-certified payment
system.** New capture devices remain untrusted until hardware attestation is
integrated; there is no administrator bypass.

See the [requirement audit](docs/PRD-2.0-IMPLEMENTATION.md) for coverage, remaining
acceptance gates and deliberate architecture differences.

## Implemented

- Admin merchant onboarding, owner provisioning, account suspension, return-domain
  allowlists, editable limits and operating thresholds.
- Email OTP, mandatory admin TOTP, optional merchant TOTP, read-only staff accounts,
  immediate staff disablement, login history and a production admin IP perimeter.
- Integer-paise order allocation, idempotency, scoped and signed API requests,
  expiry, healthy-device gating and circuit breakers with two-person reopening.
- Dual-source bank-signal matching; reference replay protection; late payments,
  CAPTCHA-protected claims, owner OTP review, escalation and manual refund records.
- Fresh-OTP QR pairing, additional-slot approvals, two-person migrations, immediate
  key revocation, signed device APIs, parser version allowlists and structured fault
  reporting without raw SMS content.
- Immutable events and audit history, protected paid state, transactional webhook
  outbox, exact-200 delivery, DNS pinning, retries, resend and acknowledgements.
- Daily CSV statement reconciliation, evidence integrity checks, anomaly alerts,
  device/breaker/delivery emails and heartbeat alert fallback.
- Responsive light/dark panels, payment search and filtered CSV export, branding,
  English/Hindi/Marathi checkout, receipt, QR, Android UPI-app links and support links.
- PHP/Laravel verification package, PHP/Node/Python examples, a WooCommerce classic
  checkout plugin and a browser popup helper. All fulfillment remains server-side.

## Local setup

Required: PHP 8.3+ with the application extensions, Composer, Node/npm and a fresh
local PostgreSQL database (or SQLite for tests). The lockfile targets Laravel 13;
this workspace was tested with PHP 8.5 and existing dependencies. Composer and a
reachable PostgreSQL server were unavailable in this session.

Copy `.env.example` to `.env` **only for a new installation**, set your local
connection details, then run:

```bash
composer install
npm ci
php artisan key:generate
php artisan migrate
php artisan db:seed
npm run build
```

Demo seeding is restricted to `local`/`testing`. Never seed a production database
or reuse sandbox data, device keys or API credentials for production.

Run the web server, queue worker and scheduler in separate terminals:

```bash
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work --queue=events,default --sleep=1 --tries=3 --timeout=60
php artisan schedule:work
```

Seeded users are `owner@example.com` and `admin@example.com`. With the local `log`
mail transport, OTP messages appear in the application log; production must use
transactional email. Admins enroll an authenticator before entering the console.

## Add a merchant

Admin → **Merchants → Add merchant** provisions a merchant and a new owner in one
transaction. Enter the UPI ID, support number, exact return domains and caps.
Existing users cannot be reassigned during onboarding. The owner requests an
email OTP; account creation does not send an invitation.

The owner configures branding, API keys and the webhook under the panel. Staff
accounts are read-only. Pairing requires another fresh email code. Additional
slots require approval; migrations require two admins. A breaker reopen request
counts as its requesting admin's first approval and needs a different admin.

Merchant activation alone does not enable orders. A verified capture device with
healthy listeners and the required heartbeat streak is also necessary.

## API contract

| Endpoint | Authentication / purpose |
| --- | --- |
| `POST /api/v1/orders` | Create key, request signature and `Idempotency-Key` |
| `GET /api/v1/orders/{id}` | Read key and signature; authoritative current state |
| `GET /api/v1/orders/{id}?event_id={event_id}` | Read key; verify the sequence of a delayed webhook even after a refund event |
| `GET /api/v1/events?after={id}` | Read key; resume immutable event history |
| `POST /api/v1/events/ack` | Read key; acknowledge an owned event |
| `POST /api/device/v1/pair` | Fresh single-use pairing token; new device remains untrusted |
| `POST /api/device/v1/heartbeat` | Device signature; fresh listener observation |
| `POST /api/device/v1/signals` | Device signature and verified device; parsed credit fields only |
| `POST /api/device/v1/faults` | Device signature and verified device; idempotent structured failures |
| `GET /api/device/v1/parser-rules` | Device signature |

Merchant requests require `Authorization: Bearer ...`, `X-UPI-Timestamp`,
`X-UPI-Nonce` and `X-UPI-Signature`. Sign this exact byte sequence with HMAC-SHA256,
using the API token's secret portion after the third underscore:

```text
METHOD\n/path?exact=query\nUNIX_TIMESTAMP\nUNIQUE_NONCE\nSHA256_HEX(raw_body)
```

The timestamp window is five minutes. Nonces are single-use, 16–128 characters.
GET requests normally have an empty body. Legacy API keys must be rotated.
Device signatures use the existing `X-Device-*` headers and the canonical path
without query parameters; see `DeviceRequestVerifier` before building Android.

Fault report fields are `client_fault_id` (UUID), `type` (`parser_failure`,
`upload_error` or `sync_error`), `source` (`sms`, `notification` or `device`),
`occurred_at`, and optional `parser_rule_version`. No raw-content field is accepted.

## Merchant verification integrations

Save the gateway order ID, **allocated payable amount**, currency and local order
mapping before showing checkout. Validate webhook HMAC over `timestamp.raw_body`,
then pull the order with the independent read key. Require a paid state and matching
order, amount, currency, reference hash, sequence and immutable verification hash.
Manual confirmation is rejected unless explicitly enabled by the merchant.

The verification hash is SHA-256 of compact JSON:

```text
[order_id, payable_amount_minor, currency, reference_hash, event_sequence, status, confirmation_method]
```

Deduplicate both order and event IDs and write fulfillment to an outbox in the same
database transaction. Acknowledge after commit and return exactly HTTP 200. Do not
ship goods from a browser redirect, UPI-app result, popup close or webhook alone.

### Laravel / PHP

`packages/hx-upi-merchant-php` is a Composer path package with auto-discovery for
Laravel. Publish `hx-upi-config` and `hx-upi-migrations`, run migrations in the
merchant application, and configure `HX_UPI_API_URL`, `HX_UPI_READ_KEY` and
`HX_UPI_WEBHOOK_SECRET`. The API URL must be an HTTPS origin without a path.

Call `LaravelPaymentHandler::handle($request, $storedExpectedOrder, $callback)`
from a server webhook endpoint. The callback writes the merchant fulfillment
outbox using the same database connection; it must not perform external effects.
A callback failure rolls back the deduplication record. Failed acknowledgements
can be retried without repeating fulfillment.

The provider schedules `hx-upi:reconcile` daily. Run the merchant's scheduler and
attach an alert listener to `hx-upi.verification_mismatch`; it receives the event
or stored verification, pulled state, and source IP when available. Mismatches
also generate critical logs. Plain PHP, Node and Python flows are in `examples/`.

### WooCommerce

Build an installable ZIP, including the same PHP verifier:

```bash
python3 packages/build-woocommerce.py /tmp/hx-upi-woocommerce.zip
```

Install on a staging store with PHP 8.3+, WooCommerce and **classic checkout**.
Configure the two HTTPS origins, separate create/read keys and webhook secret.
Set the gateway webhook to the store's `/?wc-api=hx_upi` endpoint and allowlist
the store's return host in the merchant panel. Ensure the custom plugin table was
created on activation and that MySQL named locks are supported.

The plugin persists the expected order mapping, adds the allocated paise difference
as an untaxed verification-adjustment fee, and calls WooCommerce payment completion
only after both channels agree. Refunds are handled separately by the merchant;
the plugin does not move money. Expired gateway checkouts require a new store order
or owner review. Daily checks use WP-Cron; configure reliable cron execution.
Mismatch alerts go to the WordPress administrator email and WooCommerce logs.

Contract checks pass locally. Actual WooCommerce checkout, mail, stock, order
storage, refunds and extension interactions still require staging validation.
Checkout Blocks support has not been implemented.

### Popup checkout

Self-host `public/hx-checkout.js` on the merchant site. Call
`HXUPI.openCheckout({checkoutUrl, checkoutOrigin, onReturn})` from a user click.
Use an explicit trusted HTTPS checkout origin. Popup-blocked browsers fall back
to a redirect. `onReturn` only signals window closure; refresh the merchant's own
server-verified status. Hosted checkout forbids iframe embedding.

## Reconciliation and operations

Upload **credit-only** CSV under Reports with exact headers:

```csv
reference_number,amount_minor,occurred_at
123456789012,10001,2026-10-24T10:30:00+05:30
```

Choose the corresponding statement date. Imports are limited to 5 MB / 10,000
rows, validate integer paise and unique references, and are idempotent by file hash.
Reports identify unmatched credits, amount differences, unconfirmed receipts and
paid orders absent from the statement. Source files are not retained by this flow.
This is a manual statement import, not a direct Kotak bank API connection.

The scheduler expires orders, retries webhooks/claims, records missing-source
faults, escalates disputes, monitors devices, raises anomaly alerts, emails owners
and admins, and prunes expired nonces/old OTP challenges. Anomaly thresholds and
quiet hours are configured in `.env.example`; alerts never authorize payments.
Paid evidence and audit history are retained until a retention policy is agreed.

## Deployment and acceptance

New gateway schema changes are in three migrations dated 2026-10-23/24. Apply them
with `php artisan migrate --force` **during an arranged deployment after a tested
backup**, then restart workers. No live migration or deployment was performed here.

Start from `docs/deployment/nginx.conf.example`, `supervisor.conf.example` and
`backup.sh`. Replace every placeholder. Use four distinct HTTPS hosts, TLS 1.3,
`APP_ENV=production`, `APP_DEBUG=false`, secure/encrypted sessions, persistent queue
and cache, exact admin IPs, transactional email and Turnstile keys/hostnames.
Configure trusted proxies explicitly if using a CDN; do not trust arbitrary
forwarded client-IP headers. Disable CDN caching for dynamic/API/checkout routes.

Create two real admin accounts with `php artisan gateway:create-admin` (see
`--help` for required arguments), then complete their email OTP/TOTP enrollment.
Run `php artisan gateway:readiness` for a read-only configuration check. The command
prints missing prerequisites and returns a failing exit code; passing it does not
certify bank behavior, hardware attestation or production performance.

Production artifacts must exclude simulator controllers/services/views, demo
seeders, tests and development dependencies. Production routes and runtime guards
also reject the test lab. Sandbox deployments need separate data and credentials.

Polling is enabled by default. Optional SSE (`CHECKOUT_STREAM_ENABLED=true`) uses
PHP workers and must be capacity-tested before enabling. Laravel Reverb is not
installed. Panels retain Blade/Tailwind rather than the PRD's Inertia/React stack.

Before live payments: finish Android attestation/capture, validate real bank
samples, test all four hosts and actual emails/webhooks, run PostgreSQL concurrency
and browser/device tests, restore an encrypted off-server backup into isolation,
complete security/operational review and the low-cap pilot.

## Verification

Latest local run: **67 PHP tests, 294 assertions**, plus Node/Python, popup and
WooCommerce contract checks passed. Build, Blade compilation, routes and schedules
also passed. Production readiness checks still report missing deployment setup.

```bash
php artisan test
node --test tests/integrations/*.test.mjs
python3 -m unittest discover -s tests/integrations -p 'test_*.py'
php tests/integrations/woocommerce-contract.php
npm run build
php artisan view:cache
php artisan route:list --except-vendor
CACHE_STORE=array php artisan schedule:list
bash -n docs/deployment/backup.sh
```

Tests use an isolated SQLite database. They do not validate PostgreSQL lock behavior,
real bank evidence, external delivery or browser visuals. Do not use destructive
migration/reset commands as deployment verification.
