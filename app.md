# HX Pay Android capture app — implementation handoff

**Prepared: 25 October 2026.** This file is the build brief for the Android implementation agent. It describes the gateway code inspected on this date. Recheck the referenced files before coding; sections marked **proposed** describe work that does not exist yet.

## 1. Outcome and scope

Build a native Kotlin Android application for a merchant-owned, dedicated bank capture phone. It reads approved Kotak **credit** SMS and bank-app notifications, extracts structured payment evidence locally, and signs uploads to HX Pay. Laravel, not the phone, decides whether an order is paid.

The acceptance flow is:

1. Merchant owner pairs the phone from the web panel using a fresh email OTP and a single-use QR.
2. Backend verifies the phone's hardware-backed signing key and application identity.
3. Both capture listeners become healthy; the phone sends regular signed heartbeats.
4. Merchant opens a **tracked** low-value checkout and pays its exact allocated amount.
5. Phone captures the matching SMS and notification independently.
6. Gateway validates both signals, confirms the order, updates checkout and dispatches the normal event/webhook flow.

Do not implement a payment simulator as the release app. Do not set `paid` from a QR scan, app return, timer, notification count or an “I paid” button. Do not change server trust or matching rules merely to make a demo pass.

The initial deliverable is a sideloadable capture APK, source, reproducible build, automated tests, device setup instructions and integration evidence. Distribution through Google Play is outside the current PRD scope. Sideloading does not remove Android permission or background-execution restrictions.

## 2. Environment and source of truth

| Item | Current value |
| --- | --- |
| Repository root | `/root/hx-upu` |
| Laravel project | `/root/hx-upu/upi-gateway` |
| This document | `/root/hx-upu/upi-gateway/app.md` |
| Recommended new Android project | `/root/hx-upu/android-capture` — check for an existing project first |
| Gateway origin | `https://pay.hxdev.in` |
| Device API prefix | `/api/device/v1` |
| Merchant sign-in | `https://pay.hxdev.in/login` |
| Merchant devices | `https://pay.hxdev.in/panel/devices` |
| Merchant preview / real tests | `https://pay.hxdev.in/panel/preview` |
| PRD | `../prd2.0.md` relative to the Laravel project |
| Server implementation audit | `docs/PRD-2.0-IMPLEMENTATION.md` |

The current server is a **staging deployment**, despite having public HTTPS. Its web process is behind Nginx, and TLS certificate renewal is configured. The device API currently shares the same origin as the panel and checkout; the PRD's separate device origin is a future deployment requirement. Do not hard-code either old IP address or port 8000 into the APK.

Read these files before implementing the Android protocol:

- `routes/api.php`
- `app/Http/Controllers/Device/DeviceController.php`
- `app/Domain/Devices/DeviceRequestVerifier.php`
- `app/Domain/Devices/DevicePairingService.php`
- `app/Domain/Devices/AttestationVerifier.php`
- `app/Domain/Devices/GatewayHealthService.php`
- `app/Domain/Payments/PaymentMatchingService.php`
- `app/Http/Controllers/PanelController.php`
- `app/Http/Controllers/OperationsController.php`
- `resources/views/panel/devices.blade.php`
- `config/gateway.php`
- `app/Models/Device.php`, `DevicePairing.php`, `ParserRule.php`
- `tests/Feature/GatewayFlowTest.php`, `GatewayCompletionTest.php`, `GatewayIntegrityTest.php`

Follow applicable `AGENTS.md` instructions. Do not inspect credential files, copy the server `.env` into Android, embed SMTP secrets, or ship merchant server API keys. Public origin, device UUID and public certificates are not substitutes for private credentials.

## 3. Current blockers: do not mistake these for completed features

| Area | Current behavior | Work required |
| --- | --- | --- |
| Attestation | `AttestationVerifier::status()` returns `pending_review` for no chain and `submitted` for any supplied chain. It never returns `verified`. | Implement real server-side validation and key binding before live capture can work. |
| Manual trust override | Admin device approval rejects with “Hardware attestation verification must be integrated…” | Keep it disabled; do not edit database trust flags on the live VPS. |
| Pairing challenge | Existing QR carries a token and URL; server does not validate an attestation challenge. | Implement the proposed challenge binding in section 7, or agree and test a versioned alternative. |
| Pairing result | Returns only device UUID and device status. | Persist that UUID; use heartbeat for status. Add a scoped capabilities endpoint if the app needs merchant identity/configuration. |
| Capture rules | Downloadable rules and source allowlists exist. | Validate real credit samples; define an Android-compatible extraction schema. Never invent the bank package name. |
| Native collection dashboard | No signed device dashboard/collection endpoint exists. | Add a scoped read endpoint, or use an authenticated browser panel until implemented. Local capture totals are not confirmed collections. |
| Native manual approvals | No native device approval/rejection API or owner authentication flow exists. | Implement owner authentication, fresh OTP and authorization server-side before native actions. Biometrics alone are insufficient. |
| Push | Heartbeats return unresolved alerts; no FCM device registration/delivery contract exists. | Heartbeat alerts first; add FCM registration and delivery separately. |
| Re-attestation | No refresh/rotation contract exists. | Design and test it; a certificate from initial key generation is not fresh attestation. |
| Background reliability | No Android implementation or hardware soak test exists. | Prove supported phone/OS combinations; never promise universal 24/7 execution. |

**The APK alone cannot enable auto-confirmation against the current attestation stub.** The implementation agent must complete the backend trust work too, or deliver a concrete backend patch/specification and clearly identify the release dependency.

## 4. Distinguish the three payment experiences

The web application already has three different experiences:

- **Preview simulation:** browser-only demonstration; no real payment or order.
- **Actual direct-transfer QR:** real UPI transfer for the exact amount entered (for example ₹1.00), but no tracked gateway order or automatic confirmation. It cannot prove this app's end-to-end integration. Once capture is active, such an unmatched bank credit may itself create a reconciliation/fault case.
- **Tracked checkout:** creates a real order using `OrderService`. A ₹1.00 base amount receives a matching suffix, such as ₹1.01. This is the flow to use for the Android acceptance test. Pay the exact displayed amount.

The existing bank-email path has a default ₹500 minimum and is not a substitute for the ₹1 SMS + notification test. Do not lower that threshold to hide missing Android capture.

## 5. Android project plan

### Stack and identity

Use Kotlin, Jetpack Compose, coroutines/Flow, Room for the structured outbox, Android Keystore, and an HTTPS client supporting exact request bytes and certificate pinning. WorkManager is for deferred/retry work, not a 60-second periodic heartbeat. Keep dependencies minimal and pin compatible stable versions in a Gradle version catalog; check current official documentation rather than copying old version numbers.

Suggested application ID: `in.hxdev.capture`. Treat this as a proposed ID until the release signer and server package allowlist are established. Separate debug and release package IDs, backends and signing identities. A practical starting support floor is Android 10 / API 29, subject to the actual dedicated phone and hardware-attestation checks; select current compile/target SDKs at implementation time. Do not lower target SDK to evade restrictions.

Suggested package structure inside one Gradle `app` module:

```text
core/model/          DTOs, amount/reference types, states
core/network/        exact-body HTTP transport, error mapping, TLS policy
core/security/       Keystore keys, signer, attestation, local encryption
core/database/       Room outbox, delivery receipts, capture cursor
pairing/             QR scanner, pairing state machine, recovery
capture/sms/         SMS receiver, multipart assembly, inbox backfill
capture/notification/ NotificationListenerService and provenance checks
parser/              rule models, credit classification, extraction, fixtures
sync/                upload drain, retry scheduling, heartbeat, fault reporting
health/              permission/listener/queue/network health evaluation
alerts/              local notifications, heartbeat alert deduplication
ui/setup/            permission and device setup wizard
ui/dashboard/        device health and verified collection views
ui/activity/         structured capture/upload history
ui/settings/         diagnostics, battery help, secure reset
```

Keep parser, canonical signing and retry decisions independent of UI so they can be unit-tested. Use interfaces for clock, API, key store, source readers and queue to support deterministic tests.

### Minimum screens

1. **Welcome:** purpose, bank access disclosure, HTTPS environment, app version.
2. **Pair:** QR scan and manual token fallback; clearly show the permitted server origin. Never send a scanned pairing token to an arbitrary host.
3. **Permissions:** SMS reception/backfill, notification access, notification display, camera if used, and relevant battery/autostart guidance. Show denied/restricted states with a retry/settings action.
4. **Trust:** pairing pending, attestation submitted, verification rejected, trusted, revoked. “Paired” must not be displayed as “Ready to accept payments”.
5. **Health:** listener statuses, last successful heartbeat, gateway accepting/paused state, parser version, queue depth, oldest pending upload, last capture and clock/network problems.
6. **Activity:** structured amount/source/time and upload outcome. Mask references in ordinary UI; never display or export raw SMS/notification text.
7. **Collections:** authoritative daily/monthly totals only when a server endpoint exists. Until then label local counts “Captured alerts”, not “Paid orders”.
8. **Alerts:** deduplicated unresolved heartbeat alerts with links to the authenticated merchant panel.
9. **Settings/recovery:** notification-access help, battery guidance, local diagnostic export without secrets, app update information and a guarded reset/re-pair flow.

Match HX Pay's indigo theme, light/dark modes, readable status text and accessible touch targets. Do not use color alone to signal failures. Setup must be understandable without developer terminology.

## 6. Permissions, source capture and background operation

### Permissions and components

Plan for `INTERNET`, `ACCESS_NETWORK_STATE`, `RECEIVE_SMS`, `READ_SMS` for backfill, `RECEIVE_BOOT_COMPLETED`, applicable foreground-service permissions, `POST_NOTIFICATIONS` on relevant versions, and `CAMERA` if an in-app scanner needs it. Request sensitive runtime permissions in context. Handle installer restrictions and user denial; do not assume the manifest grants access. See [Android permissions](https://developer.android.com/guide/topics/permissions/overview).

Declare the notification listener service with `android.permission.BIND_NOTIFICATION_LISTENER_SERVICE` and the documented service intent. Notification access is a separate user-granted setting, not an ordinary runtime permission. Validate callbacks and listener connection state; device policies may restrict access. Follow [NotificationListenerService](https://developer.android.com/reference/android/service/notification/NotificationListenerService).

Do not add accessibility scraping, contact/location permissions, SMS sending, OTP capture or a default-SMS role solely to work around missing credit notifications. Avoid overlays in v1. Minimize exported components and protect each necessary receiver/service appropriately.

### Capture rules

- Accept only explicitly allowlisted alphanumeric Kotak sender IDs. Current server shape is `^[A-Z]{2}-KOTAKB(?:-[A-Z])?$`, plus membership in the active rule's exact sender list.
- Obtain notification package identity from the Android notification callback, not from notification text. Confirm the real bank package and, as a client-side defense, its signing identity on the test phone.
- Never treat a messaging app notification containing copied bank text as Kotak evidence.
- Parse credits only. Reject debit, failed transaction, balance-only, promotional, OTP and ambiguous messages.
- Handle multipart SMS and multiple notification text fields without duplicating one alert.
- Extract integer paise using decimal/string parsing, a 12-digit reference string preserving leading zeroes, source and event time. Never use floating-point currency.
- Store original occurrence time separately from capture/upload time. Do not replace an old backfilled timestamp with “now” to force an order-window match.
- A notification's posted time and an SMS provider timestamp may differ from a bank's transaction time. Document the chosen convention, timezone and real-sample validation; do not invent a transaction time absent from evidence.
- Notification text can be missing, redacted, grouped or updated. A missing reference stays missing; never guess it from an amount or unrelated digits.
- Keep text in memory only as long as parsing needs it. Persist/upload structured fields only. Do not put raw data in logs, analytics, crash reports, test APK assets or clipboard.
- Never synthesize a second source. Historical SMS backfill does not recreate a missed bank notification.

### Continuous operation: validate before committing to an architecture

The PRD asks for a heartbeat every 60 seconds, a default stale cutoff of 90 seconds, and recovery after two healthy heartbeats. Periodic WorkManager has a 15-minute minimum and inexact scheduling, so it cannot supply this heartbeat. Use it for recovery/retry tasks instead. [WorkManager timing](https://developer.android.com/develop/background-work/background-tasks/persistent/getting-started/define-work).

A permanent `dataSync` foreground service is not a valid assumption: Android 15+ applies a six-hour-per-24-hour budget for affected apps. Handle service timeout callbacks and stop cleanly. [Foreground-service timeouts](https://developer.android.com/develop/background-work/services/fgs/timeout).

Choose and document the legitimate service type for continuous, user-visible bank capture. Evaluate `specialUse` with an honest declared subtype only if the actual use qualifies; do not mislabel capture as location, media playback or another unrelated service. Respect background-start and boot restrictions, including restrictions on starting `dataSync` from boot. [Service types](https://developer.android.com/develop/background-work/services/fgs/service-types).

Implement a visible, user-started capture session with an ongoing notification. Use a lifecycle-aware coroutine for heartbeats while the session is allowed to run. Keep network work out of SMS receiver callbacks: enqueue durably and schedule a drain. Use short wake locks only when measured and necessary.

On reboot, recover persisted state and follow the OS's permitted restart path; if user interaction is required, show that honestly. Do not claim automatic recovery after force-stop or before credential-protected keys/storage are accessible. A battery exemption does not make delivery guaranteed.

Health must reflect actual permission/listener/parser/queue readiness. Do not send both health flags as `true` just because a service exists. On lost notification access, denied SMS, corrupt queue or missing valid parser, mark affected health false and show the cause. Handle network loss locally; the server will stop receiving heartbeats and pause new orders.

Measure screen-off and overnight behavior on the actual phone. If 60-second heartbeats cannot be achieved on supported hardware, report the measured gap and agree a backend/deployment change. Do not silently extend the server timeout or falsify observations.

## 7. Pairing and hardware trust

### Existing QR and request

The owner generates a fresh OTP in the Devices page and then creates a pairing token. The QR currently contains JSON, not a UPI URI or a deep link:

```json
{"url":"https://pay.hxdev.in/api/device/v1/pair","token":"SINGLE_USE_48_CHARACTER_TOKEN"}
```

Validate HTTPS, exact approved origin/path, no userinfo, unexpected port, fragment or query before using it. Do not follow redirects with pairing material. The token expires after five minutes and is consumed once. Slot 1 is initially available; slots 2–4 need approval, and replacement migration requires the existing approval workflow with two admins. The phone must not choose or change the merchant/slot.

`POST /api/device/v1/pair` is the only current device endpoint without the five signature headers. Send `Accept: application/json` and `Content-Type: application/json`:

```json
{
  "pairing_token": "TOKEN_FROM_QR",
  "public_key": "-----BEGIN PUBLIC KEY-----\nBASE64_SPKI\n-----END PUBLIC KEY-----",
  "attestation_certificate_chain": ["BASE64_DER_LEAF", "BASE64_DER_INTERMEDIATE"],
  "app_version": "1.0.0",
  "metadata": {"label":"Counter phone","model":"DEVICE_MODEL","android_version":"OS_VERSION"}
}
```

Current validation: public key at most 8192 characters; chain at most eight strings, each at most 16384 characters; app version at most 32; label/model at most 80; Android version at most 20. The chain representation above is the **proposed leaf-first base64 DER convention**; the current stub does not parse or validate a representation. Implement and test this encoding on both sides before release.

Current success is HTTP 201:

```json
{"device_id":"SERVER_ASSIGNED_UUID","status":"pending_review"}
```

Use the returned public UUID in `X-Device-ID`, never the numeric database ID. Missing/expired/consumed tokens return 422 `PAIRING_NOT_AVAILABLE`; slot, migration and key reuse may return other 422 errors. The route is throttled to 10/minute. Persist the key alias and pending attempt before the request. If the response is lost after server consumption, do not create a new key and loop: show recovery, and implement a proof-of-key recovery contract or require a fresh owner-approved pairing attempt.

### Signing key choice

Use a non-exportable Android Keystore **EC P-256** key, with `SHA256withECDSA`, and export its **SubjectPublicKeyInfo PEM public key**. This fits the server's existing PEM + `openssl_verify(..., OPENSSL_ALGO_SHA256)` path. Send the DER ECDSA signature returned by Java `Signature`, base64 encoded without line wrapping. Do not send raw `r || s`, a hex signature, or a private key.

The server also supports raw base64 Ed25519 public keys with libsodium signatures, primarily exercised by current PHP tests. Do not mistake that branch for an instruction to store an exportable Ed25519 private key in preferences. Hardware Keystore interoperability must be proven with real Android-generated EC signatures.

The capture signing key must allow unattended signing during an authorized capture session; requiring biometric authentication for every heartbeat would prevent background capture. Protect destructive local actions with user authentication separately. Pin key identity to one pairing and environment. Handle key invalidation as re-pair required, not silent key replacement.

### Required backend attestation implementation — proposed protocol

A minimal challenge convention can reuse the existing high-entropy pairing token:

```text
attestationChallenge = SHA256(UTF8("hx-pair-v1\n" + pairing_token))
```

Use the 32 digest bytes as the Android key-generation attestation challenge. Server recomputes them from the presented, valid token. This convention is **not implemented today**; agree it in both codebases and add fixtures. Alternatively introduce a versioned challenge endpoint before key generation, with equivalent one-time binding.

Server validation must verify the certificate path and revocation state against the current official Android trust roots, and confirm hardware-backed security. Include supported root rotations; do not freeze the trust set to a single historical root. Perform this on the backend, not merely in the APK. [Android attestation verification](https://developer.android.com/privacy-and-security/security-key-attestation).

Decode the attestation extension correctly. Check exact challenge, attested public key equality to the submitted SPKI, expected package and release-signing certificate digest, allowed key algorithm/purpose, security level, locked/verified boot state and explicit OS/security-patch policy. Separate software and hardware enforcement fields correctly. App-supplied `model`/version metadata is not attestation evidence. [Attestation schema](https://source.android.com/docs/security/features/keystore/attestation).

Only a successful verifier may return `verified`. Invalid, unsupported, unparseable, unavailable or stale trust evidence must remain unusable for payment capture, with an actionable reason. Never add a production `TRUST_ALL_DEVICES` toggle.

Update `DeviceController::pair()` so trust verification occurs before a successful live binding or migration is committed. In particular, the current code can revoke a replacement's old device during pairing even though the new key is untrusted. Resolve this sequencing before shipping migration: do not revoke the working device because an invalid attestation merely reached the pairing endpoint.

Add a lifecycle design for fresh re-attestation/key rotation. The key's original certificate challenge cannot simply be changed later. Specify proof of the existing key, fresh challenge, replacement attested key where needed, atomic server identity update, replay protection and queued-data behavior. Do not invent a current `/reattest` route; none exists.

Attestation proves properties of the key/device/application, not the bank's message authenticity. Keep dual-source validation, reference uniqueness, caps and reconciliation.

## 8. Exact signed request contract

All requests below except initial pairing require:

```text
Accept: application/json
Content-Type: application/json             # for a JSON body
X-Device-ID: <public UUID from pairing>
X-Device-Timestamp: <Unix seconds, decimal>
X-Device-Nonce: <new random nonce for this HTTP attempt>
X-Device-Body-SHA256: <64 lowercase hex characters>
X-Device-Signature: <base64 signature, no line wrapping>
```

Canonical bytes are UTF-8 for the following five lines, with LF separators and **no final newline**:

```text
UPPERCASE_METHOD
/path/without/query
unix_timestamp
nonce
sha256_of_exact_transmitted_body
```

Example shape:

```text
POST
/api/device/v1/heartbeat
<TIMESTAMP_SECONDS>
<RANDOM_NONCE>
<BODY_SHA256_LOWERCASE_HEX>
```

Important current-code details:

- Device signing uses Laravel `Request::path()`, **excluding query parameters**. Merchant server API signing uses a different contract that includes the query. Do not copy the merchant HMAC helper into Android.
- Current device GET requests need no query. Do not introduce security-sensitive query parameters without versioning the signature contract to cover them.
- Serialize JSON once, hash/sign those bytes, send those same bytes. No pretty-printing, body rewrite or compression after signing.
- For parser-rule GET, use an empty body. Its SHA-256 is `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`.
- Nonce is nonempty and at most 128 characters. Recommended: 32 random bytes encoded as lowercase hex (64 characters).
- Request timestamp tolerance defaults to ±300 seconds. Heartbeat `observed_at` has a tighter ±120-second validation.
- Signature verification consumes the nonce before controller business validation. Every retry, including after 422, uses a new nonce/timestamp/signature. Preserve the logical signal/fault ID and original event fields.
- A generic 401 is not necessarily revocation: it can mean signature, hash, clock, nonce, or identity failure. Do not wipe the key on every 401. Stop a retry storm, diagnose clock/encoding, and require explicit recovery when identity is invalid.

Illustrative Kotlin signing core (adapt imports/transport, cover with fixtures):

```kotlin
val body: ByteArray = serializedJson.toByteArray(Charsets.UTF_8)
val bodyHash = MessageDigest.getInstance("SHA-256").digest(body)
    .joinToString("") { "%02x".format(it.toInt() and 0xff) }
val timestamp = (System.currentTimeMillis() / 1000L).toString()
val nonceBytes = ByteArray(32).also { SecureRandom().nextBytes(it) }
val nonce = nonceBytes.joinToString("") { "%02x".format(it.toInt() and 0xff) }
val canonical = listOf("POST", "/api/device/v1/heartbeat", timestamp, nonce, bodyHash)
    .joinToString("\n").toByteArray(Charsets.UTF_8)
val signature = Signature.getInstance("SHA256withECDSA").run {
    initSign(privateKeyFromAndroidKeystore)
    update(canonical)
    Base64.encodeToString(sign(), Base64.NO_WRAP)
}
// Build headers from these values and send `body` unchanged.
```

## 9. Current endpoint contracts

### Heartbeat — `POST /api/device/v1/heartbeat`

```json
{
  "observed_at": "2026-10-25T10:00:00Z",
  "sms_listener_healthy": true,
  "notification_listener_healthy": true,
  "app_version": "1.0.0",
  "parser_rule_version": 1
}
```

Required: fresh `observed_at` and both booleans. App version/parser version are optional; parser version must be at least 1 if supplied. Do not send null/zero as a valid installed version when no rules exist; omit it and show degraded setup.

Typical HTTP 200 response:

```json
{
  "status":"online",
  "gateway_open":true,
  "alerts":[{"id":12,"type":"device.offline","message":"Operator alert text"}]
}
```

Statuses can include `pending_review`, `offline`, `degraded`, `online`; a revoked key is rejected before normal handling. A verified device normally needs two consecutive healthy heartbeats. A stale gap resets the streak. `gateway_open` means the merchant can accept orders; do not interpret it as the circuit breaker having failed open. An individually online phone does not imply the whole gateway is accepting payments.

Run heartbeat separately from the upload queue so a large backlog cannot starve it. Deduplicate alert presentation by server alert ID. Heartbeats currently return the latest ten unresolved alerts, not a complete history or acknowledged inbox.

### Parser configuration — `GET /api/device/v1/parser-rules`

Signed GET with empty body. HTTP 200 returns the newest active version:

```json
{
  "version":1,
  "source":"kotak",
  "rules":{
    "sender_ids":["XX-KOTAKB"],
    "notification_packages":["example.bank.package"],
    "amount_pattern":"EXTRACTION_PATTERN",
    "reference_pattern":"EXTRACTION_PATTERN"
  }
}
```

**Those sender/package/pattern values are schema examples, not validated production rules.** No active rule returns `{"version":null,"source":"kotak","rules":[]}`. The app must tolerate `rules` being an empty JSON array in that response rather than assuming an object.

The current server stores arbitrary admin-entered extraction strings; it does not define PCRE versus Java regex syntax, capture-group semantics, credit/debit classifiers or sample-based validation. Before release, define a versioned portable schema (recommended: Java-compatible bounded patterns, named/numbered capture convention, explicit credit and exclusion classifiers) and validate it on both sides. Do not blindly compile server strings or strip delimiters and assume equivalence.

Use a safe last-known validated rule only while server acceptance still permits it. Signal ingestion checks that the submitted version is **active**, not merely known. If a version is deactivated, refresh; do not relabel old evidence as parsed by a different version. Plan explicit quarantine/reprocessing because raw source text is not kept in the outbox.

Bound text/pattern size and parsing cost; reject unsafe/invalid patterns. Fetch on pairing, startup, periodically and after parser/source rejection, with backoff.

### Captured evidence — `POST /api/device/v1/signals`

```json
{
  "signals":[
    {
      "client_signal_id":"sms-3d9108a0-7556-4ee7-bd9b-d35da1a837f8",
      "source":"sms",
      "amount_minor":101,
      "reference_number":"123456789012",
      "occurred_at":"2026-10-25T10:00:04Z",
      "captured_at":"2026-10-25T10:00:05Z",
      "parser_rule_version":1,
      "source_metadata":{"sender_id":"XX-KOTAKB"}
    },
    {
      "client_signal_id":"notification-0993cd56-adbf-43ee-a817-bb219744545b",
      "source":"notification",
      "amount_minor":101,
      "reference_number":"123456789012",
      "occurred_at":"2026-10-25T10:00:06Z",
      "captured_at":"2026-10-25T10:00:06Z",
      "parser_rule_version":1,
      "source_metadata":{"package_name":"example.bank.package"}
    }
  ]
}
```

Use real validated allowlists, not the placeholders above. Send at most 50 records; a small batch is better for latency. Required fields: ID (at most 128 characters), source (`sms` or `notification`), positive integer paise, occurrence date and source metadata. Reference is nullable or exactly 12 digits. Capture date and parser version are optional in validation, but an active parser version is necessary to pass provenance checks. Missing reference does not authorize success; the server routes it to review/fault handling.

Metadata allows **only** `sender_id` (at most 20 characters) and `package_name` (at most 150). No `text`, `raw`, `message`, `body`, OTP, payer name, balance, account number, arbitrary diagnostics or nested extras. Android never uploads `source: email`.

HTTP 202 means records were processed, not that all payments succeeded. Response is `{"data":[...per-record results...]}` in input order. Results can be `detected`, `paid`, `needs_review`, `late_payment`, `duplicate`, or `accepted:false`, and can include `signal_id`, `reason` or duplicate flags. Parse each result independently.

Stable deduplication key is `(device_id, client_signal_id)`. Persist an ID before the first attempt; keep source/payload immutable across retries. Never reuse one ID for corrected content. The existing duplicate path does not reject a changed payload, so the client must not rely on it as an update mechanism.

**Partial batch caveat:** source validation and ingestion occur inside a loop, not a batch-wide transaction. Earlier signals may commit before a later one returns 422. Retry unresolved records using their original IDs and new request nonces; never assume the whole batch rolled back. Initially, single-record uploads simplify this behavior.

The matching service requires exact amount/reference, distinct sources, trusted non-revoked devices in the same merchant, event times inside the order window, and source proximity (default 120 seconds). It also applies duplicate-reference, limit, late-payment and review rules. An upload accepted into review is delivered evidence, not an endlessly retryable transport failure.

### Structured faults — `POST /api/device/v1/faults`

```json
{
  "client_fault_id":"9a51c57a-e15e-444a-a1dd-d1763fe99070",
  "type":"parser_failure",
  "source":"sms",
  "parser_rule_version":1,
  "occurred_at":"2026-10-25T10:00:05Z"
}
```

Allowed types: `parser_failure`, `upload_error`, `sync_error`. Sources: `sms`, `notification`, `device`. ID must be UUID. Occurrence time cannot be more than five minutes in the future. Parser version is optional, positive if present. No additional top-level fields are allowed. Only trusted non-revoked devices can report faults.

New report: HTTP 201 with `accepted:true`, `duplicate:false`, `report_id`. Identical repeat: HTTP 200 and `duplicate:true`. Changed content under an existing fault ID: HTTP 409 `FAULT_ID_REUSED`. Preserve the exact logical fields and deterministic serialization order on retry because the server hashes the validated payload. Route throttle is 30/minute.

Deduplicate repeated local failures and aggregate into fault episodes; do not report every offline retry as a fresh system fault. These reports can trip the gateway breaker. Report genuine inability to process an allowed bank credit; ignore unrelated personal SMS rather than reporting it as a parser fault. Never recursively generate an upload-error report about failure to upload that same fault report.

### Common HTTP handling

| Result | Client action |
| --- | --- |
| 2xx | Interpret body/per-record outcomes, persist acknowledgements, remove only delivered queue items. |
| 401 | Diagnose time, nonce, hash/signature and key identity; bounded retry with a fresh signature; do not silently reset identity. |
| 403 / `DEVICE_ATTESTATION_REQUIRED` | Block capture upload; show trust/setup problem; do not fake a successful sync. |
| 409 | Surface immutable-ID conflict or recovery requirement; do not mutate the same record and loop. |
| 422 | Parse Laravel `errors`/`message` or `error`; fix input/rules, preserve unresolved records; account for partial batches. |
| 429 | Respect `Retry-After` if supplied and use jittered backoff. |
| Timeout / network / 5xx | Durable retry with original logical IDs and new signed-request nonce/time. |
| Redirect / unexpected HTML | Treat as wrong endpoint/auth/deployment; never follow to a different origin carrying credentials. |

## 10. Durable queue, backfill and recovery

Room outbox fields should include local ID, stable client signal ID, source, integer paise, reference, occurrence/capture times, parser version, permitted source metadata, queue state, attempts, next-attempt time and last structured error code. Keep acknowledged receipts for a bounded deduplication period; define/prune retention explicitly without discarding unresolved evidence.

Encrypt sensitive stored reference/amount data with a Keystore-protected key or an approved encrypted database design. Exclude identity, queue and encryption keys from cloud/transfer backups. Copying a database to another phone must not create a trusted clone. Never back up the private signing key.

Use a single coordinated uploader with database transactions/leases. Persist before networking; acknowledge after response interpretation. Handle crash-after-server-commit-before-local-ack through stable IDs. Batch/order deterministically. Preserve evidence if the queue is full; show degraded health, stop claiming readiness, and surface a capacity alert instead of silently dropping records.

Use bounded exponential backoff with jitter, for example an initial few seconds and a several-minute ceiling, reset after recovery. Listener callbacks should enqueue immediately. Refresh heartbeat observation time on every send; do not backfill old heartbeats as if current.

For SMS inbox backfill: persist a high-water mark plus a bounded overlap window, query only permitted senders, parse locally, and deduplicate against broadcast captures using a consistent local fingerprint. Hash a narrow set of source identifiers/content only if needed for deduplication; do not upload that raw content or retain it as debug evidence. Test multipart messages and equal-timestamp messages. Mark backfill complete only after records are safely queued.

Notification replay is not equivalent to historical SMS backfill. Active notifications can be inspected where supported and deduplicated, but dismissed/missed historical notifications cannot be assumed recoverable. Keep one-source payments for backend review.

On revoke, key loss or merchant suspension: stop accepting new live work as directed by server status, retain a clear explanation, protect pending data, and require the defined recovery/migration process. Do not upload old queued records under a new merchant or silently assign them to a replacement device.

## 11. TLS, privacy and release security

Use only the approved HTTPS origin. The PRD calls for TLS 1.3, public CA validation, certificate pinning and a backup pin; validate both client behavior and actual server negotiation before claiming compliance. Do not implement permissive hostname verification, “trust all certificates”, cleartext fallback or a production user-CA bypass.

Use SPKI pins with an independently controlled backup and a tested rotation plan. Do not pin only the current short-lived Let's Encrypt leaf certificate, or invent a backup value. Configure domain-specific trust and pinning deliberately, including how pin expiry behaves. [Android network security configuration](https://developer.android.com/privacy-and-security/security-config).

Treat root/debugger/emulator/tamper checks as defense-in-depth signals, not proof of bank evidence. A release build must not contain simulator routes, arbitrary signal editors, hidden trust toggles, debug certificates or bank message fixtures. Use isolated test flavors/backends for fixture injection.

Release signing keys belong outside source control and the APK source tree. Establish the release certificate fingerprint before configuring the backend app allowlist. Keep upgrades signed by the same planned identity; plan key rotation explicitly. Separate APK signing identity from each phone's Keystore request-signing key.

No raw bank messages, OTPs, pairing tokens, private keys, full authorization headers or SMTP secrets in logs/analytics/crash telemetry. Diagnostic exports should contain version, permission states, coarse timing, counts and error codes, not customer financial content.

## 12. Missing native APIs: explicit backend work, not assumed routes

The current five device endpoints are insufficient for the complete PRD mobile experience. Implement and test additions only with an agreed contract. The following paths are **proposed**, not currently callable:

| Proposed capability | Suggested contract and constraints |
| --- | --- |
| Device self/config | `GET /api/device/v1/me`: bound merchant display identity, slot, trust state/reason, supported capabilities, heartbeat interval/cutoff, parser policy and config version. No unrelated merchant data or secrets. |
| Collections | Signed merchant-scoped read endpoint for confirmed daily/monthly totals, currency, authoritative timezone and `as_of`; separate captured evidence metrics. Never derive confirmed volume by summing both bank sources. |
| Push registration | Signed registration/removal of this device's FCM token; minimal alert payload; server authorization remains mandatory after notification tap. |
| Native owner session | Owner email OTP and scoped short-lived app session bound to the device; define expiry, refresh, revocation and MFA semantics. Device possession alone does not grant owner powers. |
| Manual resolution | Owner-only disputed-order read/actions with fresh OTP, entered bank reference, reason, server audit, idempotency and `manual_confirmed` labeling. Require local biometric/PIN as an additional gate, not the sole authorization. |
| Trust refresh | Explicit challenge and proof/rotation contract as described above. |
| Pair recovery | Safe proof-of-key recovery for a consumed token whose success response was lost, without exposing arbitrary device identities. |

Until those APIs exist, open the existing HTTPS merchant panel in a browser/Custom Tab for collections and manual resolution. Let its normal owner login/OTP enforce authorization. Do not scrape HTML, embed administrator credentials, reuse a web session obtained elsewhere, or present a disabled native feature as completed.

If a new protocol version changes routes, fields or signature bytes, update this file and shared fixtures in the same delivery.

### Operator inputs needed before physical-phone release validation

Obtain the designated phone model/OS, the recipient bank account and working bank app on that phone, approved sanitized credit samples, verified sender IDs/package identity, the intended release application ID/signing certificate, and controlled TLS pin/backup values. Request FCM project configuration only when implementing push. Do not ask for bank passwords, UPI PINs or OTP contents; the operator performs bank login and payment approval directly.

Clarify which account each capture device is observing. Multiple bank accounts or devices must not silently mix unrelated credits; if account-level binding is needed, define the backend model/protocol explicitly rather than treating a merchant-level match as proof of bank-account identity.

## 13. Build milestones and deliverables

### A. Validate the real phone and bank evidence

- Confirm supported hardware/OS, bank app identity, sender IDs, reference availability and permission behavior.
- Establish private, approved real-sample handling; commit only sanitized fixtures that preserve parser edge cases and no personal account details.
- Demonstrate both actual credit sources exist for low-value payments. The PRD assumes this; it is not established by source code.
- Decide the portable parser schema and the honest foreground-service strategy.

### B. Create the skeleton and testable protocol layer

- Gradle wrapper and pinned dependencies, Compose setup/health screens, Room outbox, DTOs, exact signing client and mock transport.
- Build debug APK with isolated test configuration, no live-bank fabrication.
- Automated parser, amount, signature, state-machine and retry tests pass.

### C. Complete trust and pairing

- Generate EC key, challenge-bound chain and public PEM on actual hardware.
- Implement backend attestation verifier and negative tests.
- Test successful pairing, bad/expired token, malicious QR URL, duplicate key, unsupported hardware, wrong release signer and lost-response recovery.
- Prove invalid replacement attestation does not revoke a working device.

### D. Connect capture, queue and health

- Both listeners, real parser rules, signed uploads, durable retry, honest heartbeat and source health, restart/backfill, local alerts.
- Phone transitions to trusted/online only through real server verification and the required heartbeat streak.
- Device offline/degraded states pause new orders and recovery follows the configured policy.

### E. Run the tracked payment acceptance flow

- Use a real owner/admin tracked checkout, not the direct-transfer QR or simulator.
- Validate the full flow described in section 15 with low limits and bank receipt checking.
- Record measured timing and any remaining production dependencies.

### F. Finish operator features and package release

- Native dashboard/owner actions/FCM only after their backend contracts and authorization tests exist; document any browser fallback honestly.
- Provide a signed release APK, APK SHA-256 checksum, signing-certificate SHA-256 fingerprint, version code/name and supported-device matrix.
- Include reproducible commands, installation/update instructions, permissions/battery guide, failure recovery and sanitized test evidence.

Suggested Android commands after selecting the actual project/toolchain:

```bash
cd /root/hx-upu/android-capture
./gradlew --version
./gradlew :app:lintDebug :app:testDebugUnitTest :app:assembleDebug
./gradlew :app:connectedDebugAndroidTest
./gradlew :app:assembleRelease
adb install -r app/build/outputs/apk/debug/app-debug.apk
```

Run connected tests only against a designated emulator/test phone. Emulators may exercise UI/queue behavior but are not hardware-attestation acceptance evidence. Configure release signing externally; `assembleRelease` must not be reported as producing an installable signed release if signing was not configured.

Backend regression checks when its contracts are changed:

```bash
cd /root/hx-upu/upi-gateway
php artisan test --compact tests/Feature/GatewayFlowTest.php tests/Feature/GatewayCompletionTest.php tests/Feature/GatewayIntegrityTest.php tests/Feature/LivePaymentTestTest.php
```

Use test databases and fakes; never run database reset/seed commands against the VPS's current merchant data. Add focused attestation/Android interop tests, then run the relevant full suite before release.

## 14. Required automated tests

| Area | Required cases |
| --- | --- |
| Parser | Valid SMS/notification credits; ₹1 and paise; Indian formatting; leading-zero reference; multiline/multipart; duplicate updates; debit/OTP/promotion ignored; missing/ambiguous reference; disallowed sender/package; malformed rules; bounded execution. |
| Signing | Android P-256 signature accepted by PHP; exact UTF-8 bytes including Unicode; empty GET body; missing headers; uppercase hash rejected; body tampering; old/future timestamps; replay nonce; wrong key; revoked device. |
| Attestation | Valid hardware chain; wrong challenge/public key/package/signer; bad chain/root/revocation; unlocked boot/software-only evidence; malformed ASN.1; trust-source failure; replay; migration rollback. |
| Queue | Offline capture; process death; reboot; server commit before client ack; duplicate signal ID; partial batch failure; reordered records; 429/5xx/backoff; full disk/queue; immutable payload; fault episode deduplication. |
| Health | Permission denied/revoked; listener disconnected; parser absent; unverified device; stale heartbeat; two-heartbeat recovery; honest network failure; server gateway-paused result. |
| Tenant/roles | Wrong merchant cannot pair/submit/manage another device; replacement/revocation; owner versus staff; device key cannot perform owner/admin actions. |
| Security | Malicious QR destination; redirects; cleartext/certificate/pin failures; pin rotation; no private key export; backups excluded; diagnostics contain no raw text or OTPs. |
| UI | Permission recovery; large text/dark mode; offline/pending trust/revoked; no false ready/success state; missing native API shows fallback. |

Create shared fixed request-body/canonical-hash/public-key fixtures so PHP and Kotlin agree on bytes. For ECDSA, verify signatures rather than expecting identical signature bytes on repeated signing. Generate fixtures with test keys only; never export a hardware production private key.

## 15. Real-device acceptance checklist

- [ ] Pair a physical phone through the current merchant panel and fresh OTP.
- [ ] Server validates hardware trust and release application identity; no manual database override.
- [ ] Two healthy signed heartbeats produce the expected ready state.
- [ ] Active parser version uses verified real sender/package identities and approved fixtures.
- [ ] Open **tracked checkout** at the merchant preview page with ₹1 base amount.
- [ ] Pay the exact allocated amount shown, such as ₹1.01, to the intended merchant.
- [ ] Observe two independent bank sources with the same amount/reference.
- [ ] A single source leaves the order unconfirmed; the matching second source confirms it.
- [ ] Checkout updates from the backend; receipt and ledger contain the real reference.
- [ ] Signed webhook delivery and merchant-side verification work if configured; otherwise mark this part untested.
- [ ] Replaying either source does not create a second payment or fulfillment.
- [ ] Wrong amount, reused reference, unapproved source, stale/revoked phone and expired/late payments do not falsely confirm.
- [ ] Disable notification/SMS access, disconnect network, reboot and force-stop: UI and backend behave honestly; document user actions needed to recover.
- [ ] Backfill after reconnect preserves occurrence time and never fabricates missing notification evidence.
- [ ] Overnight screen-off test and longer supported-device soak complete with measured heartbeat/capture reliability.
- [ ] Verify bank statement/receipt against the resulting payment records.
- [ ] Measure proposed PRD goals: zero false confirmations, at least 99% heartbeat uptime, and payment-to-paid p95 under 15 seconds. Report actual results; these are targets, not guarantees.

Do not perform real transfers automatically from tests. The operator approves each low-value bank payment in their own UPI app. Test transfers are real money and have no automatic refund.

## 16. Final report expected from the Android agent

Deliver:

1. Android source/project path and any backend patches, with implemented versus pending scope.
2. APK artifact paths, checksums, versions and release signer fingerprint.
3. Exact build/test commands and results, distinguishing emulator tests from physical-phone proof.
4. Installation, pairing, permissions and supported-phone setup instructions.
5. Shared protocol fixtures and updated API/attestation contract documentation.
6. Real tracked-payment evidence with personal data redacted, observed latency and failure/recovery results.
7. Remaining blockers such as missing real samples, unimplemented trust, unsupported background behavior, missing native APIs or unavailable signing/FCM configuration.

Do not label the app or gateway “100% complete” merely because an APK builds or a direct QR transfer succeeds. Completion requires trusted capture and verified server confirmation on the intended physical phone.
