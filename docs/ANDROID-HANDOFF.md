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
