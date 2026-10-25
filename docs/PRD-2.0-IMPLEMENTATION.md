# PRD 2.0 implementation audit

Reviewed against `../../prd2.0.md`, updated **2026-10-24**. The user explicitly
postponed Android until the gateway work is complete. This audit separates
implemented server/web behavior from client work and production acceptance.

**Do not interpret this as 100% PRD compliance or permission to launch real-money
payments.** Hardware attestation, real bank samples, production infrastructure and
the pilot remain unverified. The repository also differs from the PRD's specified
frontend and live-update technologies.

## Requirement coverage

“Implemented” means code exists and the relevant local checks pass. External
acceptance is listed separately; it has not been silently treated as complete.

| Requirement | Status | Implementation / remaining acceptance |
| --- | --- | --- |
| Integer paise, unique amounts, 99 candidate slots | Implemented | `OrderService` serializes allocation per merchant and avoids collisions across adjacent base amounts. Reservations remain held through expiry + 48h for late-payment ambiguity; this intentionally reduces throughput. PostgreSQL concurrency/load tests remain. |
| Idempotent creation, expiry, busy/unavailable errors | Implemented | Retries return an existing response even after the device goes offline; changed request bodies are rejected. Persisted expiry and friendly checkout states. Allocation exhaustion returns `BUSY`; unavailable merchants return `GATEWAY_UNAVAILABLE`. |
| Exact SMS + notification matching | Implemented server contract | Exact amount/reference, order-time bounds, source proximity, attested/non-revoked device checks and one-use references. Real capture and real bank parser fixtures await Android/Phase 0. |
| Monotonic paid state | Implemented | Service guards plus PostgreSQL/SQLite triggers prevent paid status, amount, currency, merchant, confirmation method or paid time being rewritten. Conflicting paid evidence alerts; refunds are separate events. |
| Missing references, delayed evidence and claims | Implemented | Open claim retry, exact dual-source checks, review states, expired claims, missing-second-source fault timers, late-payment disputes. Raw email never confirms a payment. |
| Five claim attempts per order + CAPTCHA | Implemented | Persistent order-wide counter, endpoint throttling, Turnstile action/hostname validation. Missing provider configuration fails closed. Live Turnstile setup remains. |
| Owner confirmation / rejection / escalation | Implemented | Owner role, fresh purpose-bound OTP, reference and reason, atomic payment/dispute/audit writes. Expired/late orders can be reviewed. Escalation alerts admins; only the owner confirms receipt. |
| Support and recovery | Implemented | Required support phone for new merchants, customer phone/WhatsApp links and order identifier. |
| Checkout exact amount, countdown, QR, receipt | Implemented | Server-rendered QR, server-authoritative paid state, expiry/paused actions hidden, delayed claim disclosure, continued checks for late resolution. |
| Branding, locales and UPI-app buttons | Implemented; device testing pending | Restricted logo upload, English/Hindi/Marathi, generic UPI/QR and Android intents for Google Pay, PhonePe, Paytm and BHIM. Real mobile-app/browser compatibility and language review remain. Some recovery API messages are English. |
| Reverb live updates + fallback | Architecture difference | Polling enabled; optional SSE is implemented but disabled by default to avoid holding PHP workers. Reverb is not installed. Do not mark the exact Reverb requirement complete. |
| Merchant overview, payment filters and export | Implemented | Collection/success metrics, device/breaker state, status/date/amount/order filters, filter-preserving CSV and spreadsheet-formula neutralization. |
| Payment detail and delivery tracking | Implemented | Order events, bank evidence, webhook attempts, acknowledgement status, resend and refund history. |
| Email OTP, merchant TOTP and staff | Implemented | Expiring OTPs, replay-resistant encrypted TOTP, staff provisioning/read-only access, disablement and login history. Real transactional mail delivery remains untested. |
| Admin merchant lifecycle | Implemented | Search/create/detail/edit/suspend/reactivate, owner provisioning without account reassignment, editable caps/thresholds/support/return domains. |
| Admin OTP + TOTP and perimeter | Implemented application controls | Admin second factor mandatory, production exact IP allowlist fails closed, HTTPS/surface-host checks. Cloudflare/origin perimeter and trusted-proxy deployment remain external. |
| Scoped API keys, IP allowlists and request signing | Implemented | Separate create/read scopes; HMAC over method, exact URI/query, timestamp, nonce and raw-body hash; single-use nonces. Existing pre-signing keys need rotation. Optional signed API responses are not implemented. |
| Thin webhook, retries, logs, resend | Implemented | HMAC timestamp/raw body, exact HTTP 200, no redirects, private/reserved/mapped-address rejection, DNS pinning, transactional outbox, backoff, alerts and resend. Real endpoint delivery remains untested. |
| Immutable relay events and ACKs | Implemented | Numbered append-only events, tenant-scoped cursor API and acknowledgements. Paid-event-specific pulls support delayed delivery after separate refund events. |
| Two-of-two fulfillment helpers | Implemented | PHP/Laravel, Node and Python validate paid state, expected stored order/amount/currency, reference hash and event sequence against the independent webhook digest. Manual confirmation is opt-in. |
| Durable merchant deduplication and daily checks | Implemented integrations | Laravel transaction ledger/outbox callback, rollback and ACK retry coverage; daily reconciliation command and mismatch alert event. Merchant must wire the alert listener, scheduler and fulfillment worker. Node/Python examples document the durable callback contract. |
| WooCommerce plugin | Implemented classic checkout; staging required | Installable ZIP builder shares the PHP verifier. Signed create/pull/ACK, persistent local expectations, MySQL order locks, once-only completion, adjustment fee, daily checks and admin mismatch email. Contract harness passes. Real WordPress/WooCommerce, HPOS/extensions and email/stock/refund tests remain; Checkout Blocks are not implemented. |
| Embeddable popup | Implemented | Trusted-origin popup helper with redirect fallback. Window close is only a return notification and never fulfills an order. Iframe embedding is intentionally disabled. |
| Fresh-OTP QR pairing, up to four devices | Implemented server contract | Five-minute single-use QR, owner OTP, server device ID, key validation, per-slot exclusivity, revoked-key rejection. New devices stay untrusted. |
| Additional devices and migration approval | Implemented | Additional slots need admin approval; migrations need two admins. Old key revoked atomically only when its replacement pairs. History retained; device lifecycle email queued. |
| Android app, attestation and anti-tamper | Deferred by user; trust integration remains | No capture app, Keystore attestation-chain validation, certificate pinning, boot/foreground listeners, backfill, offline client queue, FCM or biometric actions. `AttestationVerifier` cannot return verified; manual trust overrides are disabled. |
| Device signatures, replay and parser provenance | Implemented server contract | Signed method/path/time/nonce/body, nonce expiry covers future timestamp skew, strict source metadata and active sender/package allowlists. Actual phone provenance still depends on deferred attestation. |
| Healthy-device pause/resume | Implemented | Listener health, fresh observed timestamps, stale streak reset, bounded counters, multi-device availability and configurable consecutive heartbeats. Merchant/device locking coordinates heartbeat, revocation and order decisions. |
| Circuit breaker and probe | Implemented | Consecutive/rolling system faults, cooldown, single probe, only that probe can close half-open, any probe system fault reopens, second trip requires two admins. Abandoned orders do not increment system fault counters. |
| Parser configuration and quarantine | Implemented server workflows | Draft/activate versions, allowlists/extraction patterns, unmatched evidence view and signed idempotent parser/upload/sync fault reports. Fault reports contain structured enums/IDs, never raw text. Real rule/sample validation remains a release gate. |
| Operational email and push fallback | Implemented server workflows | Device changes/offline, breaker trips, failed webhooks, escalated disputes and anomalies; deduplication and reminders. Heartbeats return outstanding alerts. FCM delivery belongs to the deferred Android phase. |
| Append-only audit | Implemented on supported DBs | PostgreSQL/SQLite triggers prohibit audit/event update/delete. State changes, approvals, manual actions and security events carry actor/time/target metadata. Application DB privileges must prevent trigger/schema bypass. MySQL trigger support is not implemented; deployment targets PostgreSQL. |
| Auto-confirm caps and anomaly detection | Implemented | Per-order/day caps serialized per merchant; velocity, tiny-payment and configurable quiet-hour alerts. Thresholds need tuning against real traffic. Alerts never confirm or reverse payments. |
| Daily statement reconciliation | Implemented manual import | Validated/idempotent credit CSV, missing orders, amount mismatch, unconfirmed credits and missing paid references, owner reports and admin alerts. Kotak statement/API feed integration and bank-account behavior remain external dependencies. |
| Manual refund tracking | Implemented | Owner records bank refund details and reason, cumulative refunds cannot exceed received amount, separate immutable event. The gateway never initiates a bank refund. |
| Production simulator isolation | Implemented guards; artifact verification required | Test lab routes only registered locally/in tests, service runtime guard, production seeding rejected. Deployment must exclude simulator files and use a completely separate sandbox DB/server/keys. No production artifact has been deployed here. |
| TLS, HSTS, CSP, separate surfaces, backups | Implemented app controls/templates; deployment pending | CSP nonces, HSTS, no-store, frame denial and host enforcement; TLS 1.3 Nginx, queue/scheduler supervision and encrypted off-server backup templates. Actual TLS/WAF/firewall/SSH/private DB, reliable cron, restore and patch routine are not verified. |
| Retention and privacy | Partial policy decision | Parsed-only inputs, source statement file not retained by importer, expired nonce/OTP pruning. Long-term payment/audit/backup retention is intentionally not guessed; records are retained pending the operator's policy. |
| Accessibility, mobile and weak-network behavior | Implemented basics; acceptance pending | Responsive navigation, saved light/dark theme, semantic contrast colours, focus/skip navigation, reduced motion, escaped output and reconnect states. No browser/screen-reader/weak-network audit was available. |
| PRD frontend stack | Architecture difference | Existing Blade + Tailwind + vanilla JS maintained and improved. No Inertia/React rewrite or Alpine migration. |
| Pilot metrics and release gates | Not verified | No real ₹1 dual-source payment, p95 latency measurement, 99% heartbeat measurement, independent security review, PG load test or completed low-cap pilot. |

## Decisions and migration notes

- Android work is deferred. Server APIs are prepared, but trustworthy live capture
  cannot be certified without the Android/attestation integration.
- Marathi is the regional-language default pending a different user preference.
  Support is mandatory for new merchants. Owners resolve payment disputes; admins
  handle escalations and access approvals. Default caps remain editable and need
  operator approval for the pilot.
- The 48-hour amount reservation prevents a late payment from being silently
  allocated to a newer order. It reduces the practical turnover of 99 amount slots;
  revisit only with validated bank timestamps and an explicit ambiguity policy.
- Reverb and Inertia/React are outstanding exact-stack requirements, not hidden
  behind the implemented polling/SSE and Blade equivalents.
- Three new gateway migrations add operations tables/security fields, immutable
  history triggers, protected paid state and structured device fault reports.
  Existing API keys without encrypted signing material must be rotated. Apply
  migrations and restart workers during a backed-up deployment; do not reset data.
- The merchant Laravel ledger migration belongs in each merchant application, not
  the gateway database. WooCommerce requires plugin activation to create its ledger.

## Verification

Final local result: **67 PHP tests / 294 assertions passed**. Node verification and
popup checks, all three Python checks, the WooCommerce contract harness, PHP
syntax checks, the Vite build, Blade compilation, route registration, scheduler
registration and backup-script syntax checks also passed.

`gateway:readiness` correctly returned failure in this environment: production
mode/debug settings, secure/encrypted sessions, Turnstile configuration, admin IPs,
four distinct HTTPS hosts and the database/schema connection require setup. Mail
transport configuration alone passed; actual provider delivery remains unverified.

Coverage includes merchant
onboarding/tenancy/roles, OTP/TOTP and replay, signed API/device requests, production
host/IP boundaries, dual-source/cap/claim/late recovery, immutable history and paid
state, approvals/migration, reconciliation, anomalies, breaker probe behavior,
CSV filtering, localized views, delivery safety and transactional SDK retries.

Additional checks cover Node/Python verification, popup URL/return behavior and a
WooCommerce contract harness using stand-ins for WordPress/WooCommerce services.
The harness is not a real WooCommerce installation or database concurrency test.
Build and Blade compilation are checked; no real browser visual test was available.

PHP 8.5 and existing vendor/node dependencies were available. Composer was absent
and package-download DNS was unavailable. Tests use SQLite in memory; the configured
PostgreSQL service was unreachable. Formatting uses explicit paths because this
workspace has no usable Git metadata. Optional font-fallback optimization may emit
a non-blocking Vite warning about `fontaine`; no dependency was added for it.

No production migration, data reset, live merchant onboarding, real email/payment
or webhook delivery, deployment, Android build or backup restore was performed.

## Remaining release work

1. Integrate the deferred Android app and cryptographic attestation, then validate
   real Kotak sender/package/reference samples and account behavior.
2. Configure and verify production domains, transactional mail, CAPTCHA, queues,
   perimeter, secrets, private database, simulator-free artifacts and backup restore.
3. Run PostgreSQL contention tests, browser/accessibility/mobile-UPI checks,
   WooCommerce staging tests, dependency/security review and latency/load tests.
4. Agree retention/cap/operating policies, review exact-stack differences and run
   the friendly-merchant pilot with daily real statement reconciliation.
