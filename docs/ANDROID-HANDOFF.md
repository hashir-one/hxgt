# Android and gateway handoff — 27 October 2026

Gateway baseline: `3c152dc` (Android attestation verification and scoped staging vendor exception). Android source is now in `android-capture/`. Public TLS pins are configuration values; no TLS private key, APK signing key, QR token, phone database or device diagnostic export is included.

## Android implementation status

Kotlin/Compose app, hardware P-256 Keystore identity, pinned TLS, encrypted immutable Room outbox, SMS and notification listeners, foreground capture, backfill, signed API transport and tests are included. The latest staging recovery flow is build-tested but **not yet physically tested or installed**. Earlier physical results in `android-capture/TEST-RESULTS.md` apply to the prior build only. No real bank payment or production acceptance is claimed.

The staging flow is enabled explicitly in `android-capture/gradle.properties`; release hard-disables it. It preserves the original identity and uses an encrypted journal for separate attempts. Owner QR + exact UTC expiry → phone credential check → generate separate attested key → display/share lowercase SHA-256 DER SPKI fingerprint and public PEM → pause → explicit submit after the exception is configured. No QR/private key is included in the share payload. The server remains authoritative for owner authorization and expiry.

A definitive rejection permits exact saved-request retry within expiry. Network loss, interrupted submission, malformed success or unknown response blocks retry and new preparation. An accepted UUID/key stays separate until explicit credential-gated activation. Activation currently requires stopped capture and **zero unresolved outbox records**; evidence is never reassigned, re-signed under a new key, deleted or marked resolved. Previous identity preferences are archived before activation and old Keystore keys remain.

## What the gateway agent must know

1. The existing owner-session web status endpoint is not an Android recovery API. There is no implemented contract to resolve uncertain submissions using proof of the pending key. Such attempts remain blocked. Provide a nonce-bound, authenticated proof-of-key recovery contract with authoritative outcome and atomicity against in-flight registration; a bare `not found` is insufficient. The Android app must not infer rejection from expiry.
2. Every new token needs a new challenge/key and **new exception fingerprint**. The earlier phone fingerprint is not a permanent device identifier. The prepared screen pauses before any server mutation so the operator can update the scoped exception.
3. QR JSON currently contains `url` and `token`, without expiry. The staging UI asks for the panel's exact UTC expiry (ISO 8601), bounded locally to the next five minutes. It never extends server expiry.
4. Android now sanitizes/displays the bounded `ATTESTATION_REJECTED` code and patch details. Generic HTTP 503 is conservatively treated as uncertain until the recovery contract can establish no commit.
5. The shared Kotak SMS fixtures and preset from this gateway commit are included under Android test resources and tested with actual RE2/J. Test sender/package/signature identities are synthetic. No sender, package signer or notification rules were inferred from SMS content. These tests do not authorize a dual-source production rule.
6. New staging device tests cover pause-before-submit, immutable retry after rejection, encrypted persistence, old-key/outbox preservation, expiry, unknown-outcome blocking, malformed responses, and explicit activation. They require the physical device; execution is pending because ADB currently has no connected phone.

## Commands and verification

Android: `./gradlew :app:assembleDebug :app:assembleDebugAndroidTest :app:testDebugUnitTest :app:lintDebug` from `android-capture` with Android SDK 36 and compatible JDK.

27 October result: build and test APK assembly passed; **14 JVM tests passed** (including the gateway Kotak fixtures and safe patch diagnostics); lint passed with **0 errors, 29 warnings**.

Focused phone run (after in-place app/test APK installation):

```text
adb shell am instrument -w -e class in.hxdev.capture.StagingPairingDeviceTest,in.hxdev.capture.StagingPairingUiTest,in.hxdev.capture.OutboxDeviceTest in.hxdev.capture.debug.test/androidx.test.runner.AndroidJUnitRunner
```

Repository synchronization checks: 50 PHP tests / 160 assertions passed (`PairingStatusTest`, `KotakSmsPresetTest`, `GatewayCompletionTest`) with Sodium and isolated SQLite `:memory:`. The Java server verifier suite was not rerun during the Android source sync; see the gateway acceptance record for its earlier results. No deployed server changes were made by this Windows task.

## Server review of Android commit fe562a6 — instructions for the app agent

Reviewed on 27 October 2026 against the server implementation in the same
repository. This is a source review, not a physical-phone acceptance result.
The server agent did not rerun the Android build or instrumentation. The Android
results above remain the app agent's reported results for `fe562a6`.

### Next Android task: fix these recovery boundaries before phone acceptance

1. **P1 — Do not classify an unavailable token as proof of non-registration.**
   `core/StagingPairing.kt:28–32,52–69,156–160` includes
   `PAIRING_NOT_AVAILABLE` in `definitiveRejections`, including legacy recovery.
   The server returns it for expired, missing, unapproved **and already consumed**
   tokens (`app/Http/Controllers/Device/DeviceController.php:34–42,59–64`).
   A saved legacy error with this code cannot establish whether an earlier
   request registered the pending key. Keep that legacy attempt blocked.
   Review `DEVICE_SLOT_IN_USE` and `MIGRATION_DEVICE_REVOKED` with the same
   distinction: failure of this request is not proof that no earlier request
   succeeded. Never use the mutable general-purpose `capture.error` preference
   as the sole durable receipt for a particular token/key. Persist a separate
   pairing outcome bound to the attempt, token hash and SPKI where available;
   handle older records conservatively when that provenance is absent. Keep
   `DEVICE_KEY_ALREADY_REGISTERED`, timeouts and unknown results uncertain.
   Do not turn a local checkbox or expiry into authoritative server resolution.

2. **P1 — Preserve uncertain state through every recovery entry point.**
   `core/CaptureRepository.kt:297–304` still removes the active/pending alias,
   UUID and pairing state after checking only stopped capture and empty outbox.
   `MainActivity.kt:149–161,215–246` exposes that path when staging is disabled,
   including for a legacy lost response. Retaining a Keystore key without its
   durable attempt/identity association is insufficient. Guard the repository
   method itself, including callers outside the current staging UI. An uncertain
   attempt must prevent reset and a new submission until an authoritative server
   recovery result exists. Archive resolved identity and attempt data before any
   permitted transition. Update UI messages that currently suggest preparing a
   fresh pairing after checking the panel; the panel cannot resolve a pending
   SPKI. Test both `STAGING_PAIRING=true` and `false` paths.

3. **P1 — Validate the complete success contract before acceptance/activation.**
   `core/StagingPairing.kt:149–154` and `core/CaptureRepository.kt:130–133`
   currently accept HTTP 201 with a parseable UUID alone. This server's response
   requires all of:

   ```json
   {
     "device_id": "<canonical UUID>",
     "status": "offline",
     "attestation_status": "verified"
   }
   ```

   Treat 201 with missing/invalid UUID, missing/wrong attestation status, or an
   unexpected device status as uncertain and preserve its key/receipt. Do not
   mark the local identity active. The existing staging success test doubles
   return only `device_id`; replace those with the full contract and add negative
   cases rather than treating incomplete success as valid. Verified registration
   still does not mean healthy listeners or an open payment gateway.

4. **P1 — Account for server migration at submission, not just local activation.**
   `CaptureRepository.submitStagedPairing()` submits without the outbox/session
   gate used by `activateStagedPairing()`. In the server transaction, successful
   replacement registration immediately revokes the old device/key before HTTP
   201. Waiting to activate locally cannot defer that revocation. With the
   current empty-outbox activation design, block replacement submission until
   capture is stopped and unresolved evidence is resolved under its original
   identity. The QR does not identify replacement mode, so use a conservative
   guard when a previous paired identity exists, or request a server contract
   extension before distinguishing modes. Preparation/export may still preserve
   unresolved records without submitting. If the desired workflow must replace
   a device while old records remain, propose an explicit retained/quarantined
   evidence and reconciliation design first; never mark those rows delivered,
   delete them, or sign them as new-device evidence to satisfy the guard.

5. **P1 — Serialize identity transitions with evidence creation.**
   `activateStagedPairing()` holds `pairMutex` and `uploadMutex`, but `capture()`
   and `fault()` can insert records without either lock. A callback already past
   its session check can insert old-identity evidence after the zero-pending
   check or read a changed identity during its processing. Stopping the service
   alone does not prove all listener callbacks have finished. Use a consistent
   identity-transition barrier covering capture/fault writes, background work,
   and activation/reset. Recheck identity/session under that barrier and keep
   lock ordering explicit. A deterministic concurrency test should pause an
   old-identity callback, attempt activation, and prove no evidence is lost,
   relabelled, stranded by an unnoticed late insert, or assigned to the new key.

6. **P2 — Refresh the handoff and user guidance after the fixes.**
   `android-capture/BACKEND-CONTRACT.md` has a current notice but still says in
   later sections that the verifier is unimplemented and initial pairing uses
   a stub. Rewrite those sections as current behavior or clearly separate the
   historical design. Update stale reset guidance in `PairingFailure.kt` and
   `MainActivity.kt`. Build support starts at API 29, but the server requires
   Android 13 or newer plus independent OS/vendor/boot policy; state both limits
   accurately. Keep new APK/build results separate from older phone results.

### Server contract and responsibilities

- **Implemented:** `POST /api/device/v1/pair`; exact raw challenge
  `SHA256(UTF8("hx-pair-v1\n" + token))`; PEM P-256 public key; full leaf-first
  chain; attestation/identity/trust/policy checks; atomic registration/migration;
  the HTTP 201 contract above. Consult server source for actual error formats;
  some transaction errors use `message`, attestation failures use `error`/`code`.
- **Implemented, owner browser only:**
  `GET /panel/devices/pairings/{numeric_pairing_id}/status`. Requires the same
  merchant's owner session and any required second factor. It returns token
  lifecycle and sanitized failure diagnostics, not the registered UUID/SPKI.
  Never use it as proof of pending-key registration or non-registration.
- **Still missing, server-owned work:** a pending-key recovery API. There is no
  route or finalized request/response schema to implement against yet. Leave
  uncertain recovery blocked and report the dependency. The eventual contract
  needs owner authorization, a fresh expiring nonce bound to origin/purpose/token
  and pending SPKI, proof of possession, an authoritative bound registration
  result, and atomic ordering with in-flight pairing commits. A negative result
  must either be explicitly nonterminal or fence future commits for that attempt;
  a snapshot `not found` must not authorize discarding/replacing the key. Cover
  expired/consumed tokens, revoked registrations, replay and merchant isolation.
- **Current QR:** only `url` and `token`. Manual exact expiry input remains a
  supported interim approach. The panel's `YYYY-MM-DD HH:mm:ss UTC` display can
  be transcribed as `YYYY-MM-DDTHH:mm:ssZ`; the server expiry remains authoritative.
  An ISO 8601 `expires_at` QR field is a server follow-up, not a shipped feature.
- **Staging exception:** the configured old pending-key pin is not permanent
  device identity. Supply the newly prepared key's public fingerprint and exact
  QR expiry, then wait for confirmation that the server loaded that pin before
  submit. The exception changes only vendor minimum to `20260201` and vendor
  maximum age to `8` calendar months, only in staging and for the pinned key.
  On **2026-11-01**, this February patch fails the existing month-bucket age check.
  Do not extend the age or weaken another check automatically. The Git push
  does not establish a service restart or successful physical attestation.

### Verification and return handoff

After implementing the fixes, run the Android build, JVM tests and lint commands
above in the app workspace. Extend focused tests for full success validation,
unavailable/consumed token ambiguity, durable attempt-bound rejection, every reset
entry point, stopped-capture/outbox submission guards, capture/activation races,
and process death around submission and activation. Keep the existing immutable
request retry and old-key/outbox preservation tests.

Run instrumentation on the connected phone after checking the APK signing
identity and installing in place. Never uninstall the main app or clear its
storage. Test cleanup must touch only test-owned preferences, databases and keys.
If no phone is connected, report physical checks as pending. Do not report mocked
201 responses or a successful build as physical registration.

Push the app fixes and update this handoff with commit, changed files, checks
actually run, unresolved server dependencies, and whether a safe fresh preparation
is ready. Exchange public fingerprints through the agreed handoff; keep QR tokens,
private keys, signing stores, phone data, and raw bank messages out of Git.
