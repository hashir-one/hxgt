# Attestation implementation acceptance — 25 October 2026

## Executed results

| Check | Observed result |
| --- | --- |
| Java dependency installation | `apt-get install -y openjdk-21-jdk-headless` completed; OpenJDK 21.0.12.1 installed. |
| Official verifier build | `GRADLE_USER_HOME=/tmp/hx-gradle-cache ./gradlew --no-daemon installDist` passed with pinned dependencies and checked Gradle distribution checksum. |
| Fixture generator | `GRADLE_USER_HOME=/tmp/hx-gradle-cache ./gradlew --no-daemon fixtures` passed; 32 synthetic certificate fixtures generated. |
| Attestation regressions | 53 tests, 558 assertions passed, using the actual local JVM verifier and synthetic signed certificate chains with fake trust HTTP responses. |
| Full Laravel regression suite | **143 tests, 979 assertions passed**, 26.690 seconds. SQLite `:memory:` only. |
| PHP style | Pint passed for changed PHP files. |
| Whitespace/diff | `git diff --check` passed. |
| Live trust/runtime check | `php artisan gateway:attestation-check`: staging, `in.hxdev.capture.debug`, **2 roots**, **1,765 listed serials**, runtime ready, trust fresh. Counts can change as Google updates its feeds. |
| Service activation | Restarted `hx-upi-web`, `hx-upi-queue`, `hx-upi-scheduler`; all active/running, each NRestarts=0 after restart. |
| HTTPS | `https://pay.hxdev.in/up` returned **200**. |
| Existing device | ID 1, slot 1, UUID `5b6874a5-54ef-4adf-80a7-e464deb645d2`: still submitted/pending_review, revoked_at=null, before and after deployment. |
| Physical iQOO | **Not verified in this session.** No fresh phone chain or actual pairing/payment acceptance was supplied or performed. |

No deployed merchant database was reset, seeded or migrated. The only live device
queries were read-only. No existing device's trust flags were edited. Synthetic
certificates do not establish trust in the iQOO and are not accepted by live roots.

Positive regression coverage: synthetic TEE, StrongBox and remotely provisioned
chains; exact raw challenge/key binding; successful atomic migration; independent
release identity configuration. Negative coverage: wrong raw/hex challenge,
package/signer/shared UID, missing app identity, software security, imported key,
unlocked/unverified/self-signed boot, missing boot policy, old OS, missing/stale/
future/invalid patches, software-enforced patches, wrong key parameters/purpose/
digest, appended leaf/extra extension, malformed extension, expired certificate,
bad signature, key mismatch, missing chain, revocation of every chain position,
SUSPENDED/unknown listed status, missing/stale/unavailable/malformed trust feeds,
untrusted root, token replay, token/approval/expiry/consumption/merchant races,
duplicate-key rollback, safe audits, and staging/release separation.

## Exact changed files

The complete source/fixture inventory with SHA-256 file hashes is in
[`ANDROID-ATTESTATION-FILES.txt`](ANDROID-ATTESTATION-FILES.txt). It includes every
file introduced or modified for this task, including individual vendored upstream
source files, wrapper files, Gradle lockfile and certificate fixtures. Generated
build/cache artifacts and this manifest's own hash are excluded. `.env` is an
additional local-only configuration change and is deliberately not copied or hashed.

Application changes:

- `app/Domain/Devices/AttestationVerifier.php`
- `app/Domain/Devices/AndroidAttestationTrust.php`
- `app/Domain/Devices/AttestationFailure.php`
- `app/Http/Controllers/Device/DeviceController.php`
- `app/Support/AuditLogger.php`
- `app/Console/Commands/CheckAndroidAttestation.php`
- `config/attestation.php`
- `.env.example`

Verifier package: all source files under `packages/android-attestation` listed in
the manifest. `UPSTREAM.md` records source provenance and dependency differences.

Tests:

- `tests/Feature/AndroidAttestationTest.php`
- `tests/Feature/GatewayCompletionTest.php` (updates the legacy test that accepted an unattested migration)
- `tests/TestCase.php` (guard before RefreshDatabase against any non-memory database)
- `tests/Fixtures/AndroidAttestation/*.json` (32 individually listed fixtures)

Documentation:

- `app.md`
- `docs/ANDROID-ATTESTATION.md`
- `docs/ANDROID-ATTESTATION-ACCEPTANCE.md`
- `docs/ANDROID-ATTESTATION-FILES.txt`

Pre-existing edits to `OperationsController.php` and the admin operations view,
and the earlier migration-attestation guard in `DeviceController.php`, were
preserved. Existing unrelated untracked files were not treated as this change.

## Configuration and deployment

The exact staging `.env` values, Java/dependency installation commands, repeatable
build/test/deployment commands, failure-code reference, and fresh owner-authorized
pairing/outbox retention procedure are in
[`ANDROID-ATTESTATION.md`](ANDROID-ATTESTATION.md).

Executed full-suite command:

```bash
APP_CONFIG_CACHE=/tmp/hx-attestation-test-config-absent.php APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
```

Executed activation/check commands after configuring staging:

```bash
php artisan config:clear
php artisan gateway:attestation-check
sudo systemctl restart hx-upi-web hx-upi-queue hx-upi-scheduler
sudo systemctl show hx-upi-web hx-upi-queue hx-upi-scheduler --property=Id,ActiveState,SubState,NRestarts
curl --fail --silent --show-error --output /tmp/hx-attestation-health.html --write-out 'HTTPS health: %{http_code}\n' https://pay.hxdev.in/up
```

System service inspection needed access outside the workspace sandbox; the
privileged check above succeeded. The readiness command is read-only and explicitly
reports `physical_device_verified=false`; it is not a device-approval action.

## Staging vendor exception follow-up — 25 October 2026

- Added an explicit opt-in exception for one pending key, eligible only in
  `APP_ENV=staging`. The supplied iQOO SPKI fingerprint and active local settings
  are documented in `ANDROID-ATTESTATION.md`.
- Vendor exception: minimum `20260201`, maximum age `8` calendar months. The
  existing `YearMonth` calculation accepts February during October and rejects
  it from 1 November. Baseline minimum `20260401` and age `6` remain unchanged.
- Other keys, production, Android/boot patch policy, and all other attestation
  checks retain the baseline behavior. Success records the effective exception
  in attestation evidence and the pairing audit.
- Added seven synthetic fixtures (39 total). The affected attestation and
  pairing-status suites passed: **92 tests, 931 assertions**, isolated SQLite
  `:memory:` and fake external trust endpoints. Cases include both production
  minimum/age rejection, exact key binding, malformed configuration, request
  override rejection, all vendor date validity checks, unchanged OS/boot age
  checks, and the November rollover. Formatting and `git diff --check` passed.
- Gradle could not open its daemon socket inside the current sandbox. Compiled
  the complete runtime and fixture sources directly using the cached Kotlin
  2.2.0 compiler, Java 21, and the existing pinned dependency jars; generated the
  fixtures and installed the runtime jar. The tests exercised this rebuilt jar.
  Normal builds remain `./gradlew --no-daemon installDist fixtures`.
- Read-only CLI inspection confirmed staging, no configuration cache, the
  supplied key pin, vendor exception `20260201`/`8`, and baseline `20260401`/`6`.
- Unlike the earlier deployment recorded above, service restart in this turn
  was blocked: `systemctl` could not connect to the system bus (`Operation not
  permitted`). Live worker reload and physical pairing were not verified.
- No deployed database, device state, or unresolved outbox records were changed.
  The app reports no pending-key retry capability; fresh pairing requires an app
  recovery flow and registration of its new pending key fingerprint.
