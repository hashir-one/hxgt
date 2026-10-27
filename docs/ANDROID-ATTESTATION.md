# Android hardware attestation — staging deployment and recovery

Server: https://pay.hxdev.in. Implemented 25 October 2026.
`android-capture/BACKEND-CONTRACT.md` was not present in this checkout.
This document supplements `app.md`; it does not claim physical iQOO acceptance.

## What verifies a device

`POST /api/device/v1/pair` accepts an owner-authorized, unconsumed, approved,
unexpired token, a P-256 SubjectPublicKeyInfo PEM public key and the **full**
leaf-first certificate chain (base64 DER, or PEM; 3–8 certificates including root).
The challenge must be exactly 32 binary bytes:

```kotlin
val challenge = MessageDigest.getInstance("SHA-256")
    .digest(("hx-pair-v1\n" + pairingToken).toByteArray(Charsets.UTF_8))
// Use challenge directly in KeyGenParameterSpec.Builder.setAttestationChallenge().
// Generate EC secp256r1, PURPOSE_SIGN (optionally VERIFY), DIGEST_SHA256.
```

A new token requires a new Keystore key and chain. A certificate generated for
an earlier token, a hex/base64 challenge string, or the saved chain digest cannot
verify a device. No private key leaves Android Keystore.

Laravel invokes a local Java 21 process over stdin using an argv array, without
a shell, network listener, or credentials on the command line. The wrapper uses
the maintained [official Android verifier](https://github.com/android/keyattestation),
pinned at `621149c4ebaeeea844d6f43098687c73c4d370a3`. It validates chain signatures
and structure before using the parsed attestation extension. Only an attestation
extension at the target, with no additional occurrences, is supported. An optional
provisioning extension must be immediately above it. Appended-leaf and delegated
ATTEST_KEY chains are rejected. Canonical re-encoding rejects ambiguous/duplicate
authorization fields; all certificate validity periods are enforced, including
factory chains for which upstream allows expiry. These are deliberate stricter
compatibility limits, never trust fallbacks.

Each attempt fetches both fixed HTTPS endpoints, with TLS validation, no redirects,
bounded size/time, and **no stale/offline cache fallback**:

- https://android.googleapis.com/attestation/root
- https://android.googleapis.com/attestation/status

Freshness requires a valid HTTP Date and Cache-Control max-age, with age below
the smaller of the advertised lifetime and 86,400 seconds. Google's endpoints
currently advertise 86,400 seconds. Missing freshness metadata or unavailable,
malformed, stale data rejects pairing. Every chain serial, including the root,
is checked; all entries in Google's status list (including SUSPENDED or unknown
statuses) are denied. Trust is not selected from the submitted chain or a bundled
snapshot. See [Android's verification guidance](https://developer.android.com/privacy-and-security/security-key-attestation).

Required policy:

- Exact single package and single APK signer SHA-256 digest; shared UID packages
  or multiple signer digests are rejected.
- Attestation and KeyMint security levels must both be TEE or StrongBox.
- Hardware-enforced generated EC P-256, 256-bit signing key, SHA-256 digest;
  the certificate's SPKI must match the submitted canonical public key.
- Hardware-enforced locked bootloader and VERIFIED boot, with boot key/hash.
- Android 13 or newer and all three hardware-enforced patch fields, with explicit
  minimum dates and a rolling maximum age. Software-only policy fields cannot substitute.
- OS patches use YYYYMM; vendor/boot patches use valid YYYYMMDD dates. Missing,
  malformed, future or stale patch levels fail. Rolling age uses calendar months.

Only success creates `attestation_status=verified`, initially `status=offline`.
Normal healthy signed heartbeats are still required before payment capture is
ready. Merchant and pairing rows are locked, and token hash, expiry, approval,
consumption and merchant status are rechecked after cryptographic verification.
Migration revocation, new-device creation, token consumption and success audits
commit together. Failure rolls back revocation and leaves the token available
until its original expiry. No manual trust-approval endpoint is enabled.

Audits contain bounded failure codes, internal record references, policy version,
verification time, root fingerprints, security level and patch dates. Pairing
audit hooks omit request headers and addresses. No tokens, chains, device IDs from
attestation, certificate subjects, private keys or upstream exception text are
logged. The chain digest is retained only as a correlation value, never evidence.

## Explicit staging configuration

Set these in the server's `.env`; do not put `.env` in the APK or source control:

```dotenv
APP_ENV=staging
ANDROID_ATTESTATION_ENABLED=true
ANDROID_ATTESTATION_STAGING_IDENTITY_ENABLED=true
ANDROID_ATTESTATION_STAGING_PACKAGE=in.hxdev.capture.debug
ANDROID_ATTESTATION_STAGING_SIGNER_SHA256=a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588
ANDROID_ATTESTATION_MIN_OS_VERSION=130000
ANDROID_ATTESTATION_MIN_OS_PATCH=202604
ANDROID_ATTESTATION_MIN_VENDOR_PATCH=20260401
ANDROID_ATTESTATION_MIN_BOOT_PATCH=20260401
ANDROID_ATTESTATION_MAX_PATCH_AGE_MONTHS=6
```

As of October 2026 the minimum month is April 2026. The rolling six-month policy
continues advancing even if the fixed floors are unchanged. A failing phone must
be updated or replaced under the normal policy. The explicitly authorized staging
test exception below does not alter these baseline settings.

Production requires `APP_ENV=production`, explicitly configured
`ANDROID_ATTESTATION_RELEASE_PACKAGE` and `ANDROID_ATTESTATION_RELEASE_SIGNER_SHA256`,
and the same explicit patch-policy settings. There is no staging fallback. The
known staging signer, `.debug` packages, and configured staging identities are
rejected in production. No release signer has been supplied or enabled here.
`.env.example` leaves attestation disabled and identities/policy empty by default.

### Single test-key vendor exception (staging only)

The test phone reports vendor patch **2026-02-01** and Android patch **2026-08-01**.
Its authorized exception uses a vendor minimum of `20260201` and a vendor-only
maximum age of **8 calendar months**. Both values are necessary: lowering only
the minimum would still fail the normal six-month age check. In October 2026,
eight calendar months reaches February; on **2026-11-01** this February patch
fails the rolling age check again. Do not automatically extend the allowance.

The local staging `.env` enables these limits for the iQOO pending key exported by
the app's opt-in diagnostic on 25 October 2026. Its DER SPKI SHA-256 fingerprint is
`d1f6964a1b29e89d6a5a6f59477fe9ebcca164fd7dc75c9fc8ef11e3ebae6744`.
For subsequent authorized pending keys, configure:

```dotenv
ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_ENABLED=true
ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_SPKI_SHA256=<64 hex SHA-256 of pending public key DER SubjectPublicKeyInfo>
ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_MIN_PATCH=20260201
ANDROID_ATTESTATION_STAGING_VENDOR_EXCEPTION_MAX_AGE_MONTHS=8
```

Replace the placeholder with the actual fingerprint before enabling. Never use
the APK signing fingerprint, a certificate fingerprint, device UUID, model name,
or the old paired key. For a pending public-key PEM provided by the app:

```bash
openssl pkey -pubin -in pending-pairing-public-key.pem -outform DER | openssl dgst -sha256
```

The app diagnostic reports that the current build cannot resubmit a pending key
and does not persist the pairing request/token. Configuring this fingerprint does
not repair that client limitation or revive its expired QR. The app needs a
non-destructive prepare/submit recovery flow: retain the old identity, key and
outbox, create a separate pending key for a fresh owner-authorized QR, expose its
SPKI fingerprint before submission, then submit only after the server pin is
updated. Retrying or resolving an uncertain result must respect token expiry and
the original challenge/key binding. Never blindly delete a pending key after a
network timeout; the server may already have consumed the token.

The exception is eligible only when `config('app.env')` is exactly `staging`,
the explicit flag is true, and the canonical submitted SPKI matches the pin.
The full chain must still verify and authenticate that same key. Missing or
invalid enabled exception settings fail closed. Other keys use the baseline;
production, testing, and local environments cannot activate this exception,
even if its settings are present in cached configuration.

Android/boot minima and maximum ages, vendor presence/format/future checks,
app identity, hardware, boot state, key binding, challenge, certificate trust,
revocation, owner authorization, approval, expiry, and migration checks remain
enforced. A successful exception records its scope and effective vendor limits
in device attestation evidence and the pairing audit, without logging the pin.
The exception changes pairing eligibility only; it does not rewrite device
records, keys, approvals, or unresolved outbox records.

Build the updated verifier with `installDist` as below before activation. After
updating the staging environment, refresh any Laravel configuration cache and
restart long-running application processes using the normal deployment workflow.
Use a fresh owner-authorized QR and obtain the pending key fingerprint from the
app. A new QR requires a new key/challenge and therefore a new pin. Coordinate
pinning and retry within the QR's validity; do not extend QR expiry or reuse a
chain from an expired QR. Keep the previous key and unresolved outbox intact.
Disable the exception after the test by setting its flag to `false` and refreshing
configuration. This restores baseline checks for future pairings; it does not
revoke an already paired device.

## Install, test and deploy

No Composer dependency or database migration is added. Java is a new runtime
dependency. Upstream sources, license, Gradle wrapper/checksum and dependency locks
are included in `packages/android-attestation`; Bouncy Castle is pinned to 1.86.
Test utilities and synthetic fixture generators are excluded from runtime jars.

```bash
cd /root/hx-upu/upi-gateway
sudo apt-get update
sudo apt-get install -y openjdk-21-jdk-headless
cd packages/android-attestation
GRADLE_USER_HOME=/tmp/hx-gradle-cache ./gradlew --no-daemon installDist
cd ../..
# All tests refuse to start unless the booted database is SQLite :memory:.
# A deployed config cache must not be loaded by tests; use a separate cache path.
APP_CONFIG_CACHE=/tmp/hx-attestation-test-config-absent.php APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
php artisan config:clear
php artisan gateway:attestation-check
sudo systemctl restart hx-upi-web hx-upi-queue hx-upi-scheduler
systemctl is-active hx-upi-web hx-upi-queue hx-upi-scheduler
curl --fail --silent --show-error https://pay.hxdev.in/up
```

Build on a staging/CI checkout before production deployment. Preserve the complete
`build/install/hx-android-attestation/lib` directory; Laravel invokes Java with
that classpath. Running without Java/artifacts fails closed with 503. Do not run
`migrate:fresh`, database reset or seed commands on this deployment. The tests use
synthetic roots injected through HTTP fakes, with stray network requests disabled;
there is **no** configuration switch for synthetic roots on the server.

Optional fixture regeneration (isolated development only):

```bash
cd /root/hx-upu/upi-gateway/packages/android-attestation
GRADLE_USER_HOME=/tmp/hx-gradle-cache ./gradlew --no-daemon fixtures
```

Fixtures contain only synthetic certificates/public keys and a fixed test token.
They are not signed by Google and cannot verify against live Google roots.

## Bounded responses and recovery

Attestation rejection returns `{"error":"ATTESTATION_REJECTED","code":"..."}`.

`PATCH_POLICY` now includes bounded diagnostic `details`: `field`, `reason`,
optional `actual`, `minimum`, `max_age_months`, and `server_date`. Only patch-policy
values are retained in audit metadata. The owner Devices page polls the latest
pairing status and shows these details without exposing tokens/certificates.
The Android app must display the error/code/details instead of swallowing a
non-201 response. Earlier audit records containing only a code cannot reveal the
precise failed patch field; use fresh owner-authorized pairing to collect details.
This is a diagnostic change, with no change to trust or patch requirements.


| HTTP | Codes | Action |
| --- | --- | --- |
| 422 | ATTESTATION_REQUIRED, ATTESTATION_MALFORMED, CHAIN_INVALID, ROOT_UNTRUSTED, EXTENSION_INVALID, EXTENSION_POSITION, CERTIFICATE_TIME | Obtain the complete fresh Keystore chain; reject unsupported/delegated or invalid chains. |
| 422 | CHALLENGE_MISMATCH, PUBLIC_KEY_MISMATCH | Generate a fresh key bound to the current token; submit that key and its chain together. |
| 422 | APP_IDENTITY | Use the APK matching the configured package and signing identity. |
| 422 | HARDWARE_POLICY, KEY_POLICY, BOOT_POLICY, OS_POLICY, PATCH_POLICY, CERTIFICATE_REVOKED | Resolve the hardware/OS/boot/security condition; never manually approve. |
| 503 | TRUST_UNAVAILABLE, TRUST_STALE, TRUST_DATA_INVALID | Restore fresh official trust data; retry within the token lifetime. |
| 503 | ATTESTATION_NOT_CONFIGURED, VERIFIER_UNAVAILABLE | Repair deployment configuration/build/runtime. |

Invalid/expired/consumed/unapproved tokens return 422 `PAIRING_NOT_AVAILABLE`.
Transaction failures retain existing bounded messages such as DEVICE_SLOT_IN_USE,
DEVICE_KEY_ALREADY_REGISTERED, MERCHANT_SUSPENDED and MIGRATION_DEVICE_REVOKED.
Retries never extend the token lifetime. An unknown outcome after a lost HTTP
201 response must not trigger deletion of either key or the local outbox; a
proof-of-key recovery API is not currently implemented.

## Fresh pairing for the existing iQOO

Existing public device UUID: `5b6874a5-54ef-4adf-80a7-e464deb645d2`.
Read-only inspection found numeric device ID **1**, slot **1**, `attestation_status=submitted`, `status=pending_review`, and no revocation. Its digest-only record cannot be reverified. Do not approve it, rewrite its trust
flags, delete it, uninstall the APK, clear app storage, or delete its Keystore key.

1. On the phone, pause outbox uploads and preserve the Room database, pending
   records, original client-signal IDs/timestamps, old device UUID and old key
   alias. Record pending/acknowledged counts. Use an encrypted, app-supported
   backup if available; never export SMS bodies or private keys to server logs.
   If the Android build cannot retain both old and new pairing state, **stop and
   implement that retention before pairing**. Server changes cannot preserve a
   phone database that the Android UI clears.
2. Sign in as the merchant **owner** at https://pay.hxdev.in/panel/devices. Find
   the UUID above, note its displayed numeric record ID and slot. Select “Replace
   existing device” and request migration approval for that numeric record ID.
3. Two distinct administrators approve the migration through the existing approval
   workflow. This authorizes replacement; it does not certify hardware or approve
   the old digest. Do not click a manual device-trust approval action.
4. The owner requests a fresh email OTP, then creates a replacement pairing QR
   for that same slot and old numeric device ID. The token lasts five minutes.
5. In a separate pending key alias, generate an attested P-256 key using the exact
   raw challenge above. Keep the old alias and unresolved outbox intact. Submit
   the new key and **full** certificate chain to the staging endpoint.
6. On rejection, display the bounded reason, retain both local states, and fix the
   cause. The server leaves the old record and token unchanged (unless another
   authorized actor changes them); expiry still applies. If expired, obtain fresh
   owner authorization/token and generate a new pending key.
7. On HTTP 201 only, atomically store the new UUID/key as the active pairing. The
   server retains the old device record but revokes its signing key. Keep old
   unresolved outbox rows in a separate retained/quarantined partition associated
   with the old UUID. **Do not relabel/re-sign them as newly captured evidence
   from the verified device, change their timestamps, mark them acknowledged, or
   delete them.** There is no backend outbox-transfer endpoint. Reconcile those
   payments through the existing owner/admin review process with independent bank
   evidence and an audit trail; retain records until resolution is acknowledged.
8. Verify new signed heartbeats and both capture listeners, then create a tracked
   low-value checkout and pay the exact allocated amount. Confirm independent SMS
   and notification evidence, order status and webhook outcome. A direct ₹1 QR
   transfer alone is not this acceptance test.

## Acceptance record

Results are recorded after running the suite and deployment checks below. A
synthetic certificate success proves server logic only; it does not prove the
physical iQOO's chain, signer, patch levels, boot state, listeners or payment flow.

- Physical iQOO verification: **not performed**; fresh owner-authorized pairing is required.
- Existing device trust: never promoted from its saved digest.
- Database reset/seed/migration against the deployed merchant database: **none**.

Exact implementation files and final command results are listed in
`ANDROID-ATTESTATION-ACCEPTANCE.md` alongside this document.
