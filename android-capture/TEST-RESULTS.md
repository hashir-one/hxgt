# Build and test results

## Message clarification — 27 October 2026

Installed the attestation/recovery message fix in place after reconnection. The focused physical staging UI test passed (1 test); build, 19 JVM tests and lint (0 errors / 30 warnings) passed. Capture preferences remained unchanged. Current installed APK SHA-256: `37B9AF6E66C12078AEE5BEE4F56FE2A3415D8F568FC4FBD7116BE5101F02EBD5`. No pairing submission or recovery override was performed. The review-fix results and hash below describe the preceding build.

## Review fixes — 27 October 2026

Implemented the gateway review at `52dff7e`. Built debug application/test APKs and ran JVM tests/lint with **both `hxStagingPairing=true` and `false`**. Each passed 19 JVM tests, including canonical success-contract validation, attempt receipt binding, ambiguous failures, two deterministic identity-barrier concurrency cases and gateway RE2/J fixtures. Lint: 0 errors / 30 warnings.

Physical iQOO / Android 16 results:

- Staging enabled: **19 tests passed** (11 staging lifecycle/crash/guard tests, 2 legacy recovery boundary tests, 2 legacy pairing tests, 1 staging UI test, 3 immutable-outbox tests).
- Staging disabled: **8 tests passed** (2 legacy recovery boundary tests, 2 legacy pairing tests, 1 legacy UI test, 3 immutable-outbox tests).
- Installs used `adb install -r`, with the existing debug signer verified. The tested staging APK was restored afterward. Only the temporary test package was uninstalled; the main app remains installed.
- The main capture preferences file's SHA-256 was identical before and after installation and both test runs. Tests used isolated preferences/databases and cleaned only recorded test-owned key aliases. No real pairing request, bank SMS/notification, payment or outbox resolution was performed.
- Console evidence remains local in `artifacts/physical-device-tests-review-fixes-staging.txt` and `artifacts/physical-device-tests-review-fixes-nonstaging.txt`; phone-specific diagnostics are excluded from Git.

Current installed staging APK SHA-256: `D1B6500C97B0E7A0C55EE5B0AB9CAD0C3CD8D474A9961E5E1F3DCFD248891FC5`.
Debug signer SHA-256: `a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588`.

The existing phone's legacy pending attempt lacks a bound receipt and **correctly remains blocked**. A new owner QR cannot bypass this. The server pending-key recovery contract is still required. Synthetic successful responses establish implementation behavior, not physical registration or production acceptance.

## Earlier delivered build — 25 October 2026

25 October 2026. Debug 0.1.0-debug (version code 1), package `in.hxdev.capture.debug`, base origin `https://pay.hxdev.in` as requested.

| Check | Result |
|---|---|
| Android assembleDebug / assembleDebugAndroidTest | Passed |
| JVM tests | 12 tests passed, zero errors/failures, including pairing error sanitization |
| Android lintDebug | Passed; zero errors, 27 warnings |
| Physical Android instrumentation | Latest recovery run: 6 tests passed on iQOO Neo 9 Pro / I2304, Android 16 API 36, operator-reported OriginOS 6. Earlier pinned build: 7 tests passed, including opt-in live pinned TLS 1.3 verification |
| PHP full suite | 97 tests, 446 assertions passed with Sodium, isolated in-memory SQLite and process-only test APP_KEY |
| Laravel changed PHP files formatting | Passed explicit-path Pint run |
| Release guard with missing TLS pins | Refused release as expected; no signed release APK produced |
| APK signature verification | Passed; debug signer only |

Physical tests cover encrypted outbox persistence/deduplication/AAD identity, immutable retry and duplicate server acknowledgement, 403 identity blocking with evidence preserved, unpaired setup gating, empty activity/settings, QR scanner launch with its camera preview active, and a trusted pinned TLS 1.3 connection to the live server. The TLS test makes one read-only unauthenticated GET to the parser-rules endpoint and requires explicit instrumentation argument `-e verifyLiveTls true`; HTTP authorization is not asserted by this transport-only check. Other tests use isolated databases/preferences and synthetic evidence; they do not read bank messages or perform a transfer. They do not prove successful optical decoding of a real merchant QR or successful live pairing.

Earlier UI attempts failed due to activity foreground/retained navigation state and an ambiguous Overview selector. The final harness explicitly brings this app forward, selects its clickable Overview tab and scrolls to relevant content. Initial console evidence without pins: `../artifacts/physical-device-tests.txt` (`OK (6 tests)`). Latest console evidence after configuring both pins: `../artifacts/physical-device-tests-pinned.txt` (`OK (7 tests)`). The older Gradle connected-test HTML report predates these corrections and is not the final result. All 11 JVM tests were rerun successfully for the pinned build. The lint and backend results above are from the earlier implementation verification; no backend runtime changes were made for TLS configuration.

## Delivered APK

`../artifacts/hx-pay-capture-0.1.0-debug.apk`

SHA-256: `69EDAB4CDC2A0A68EE1B566A0A192A317DADF6141F6B5079D9E6F8CD1538E829`

Debug signing-certificate SHA-256: `a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588`

Updated in place on the authorized physical phone using ADB, preserving app data. Both operator-supplied pins are compiled into this APK via `gradle.properties`. Trusted TLS 1.3, hostname and active SPKI match passed on the development machine and the phone. The backup key remains with the operator; backup certificate issuance/deployment is not tested. The missing-pins network gate is cleared; actual pairing and backend trust remain separate. See `TLS-PINS.md` and `../artifacts/tls-pin-verification.txt`.

## Pairing recovery update

The phone retained a generated key alias without a returned device UUID. A new pairing was refused with `PAIRING_RECOVERY_REQUIRED`, but feedback could be missed above the scrolled setup form. Setup now shows a direct recovery action, and action failures appear in a dialog with bounded, actionable error codes. Recovery requires phone-lock confirmation, a stopped capture session and no unresolved outbox evidence. Old keystore keys and receipts are preserved.

The updated APK was installed in place without clearing data. All 12 JVM tests and lint passed for this build. The final focused physical run passed all 6 tests: two isolated pairing recovery tests, the recovery screen test and three outbox tests. Evidence: `../artifacts/physical-device-tests-pairing-recovery.txt`. The first screen attempt timed out while the activity was unavailable; after the operator unlocked the phone, the recovery screen and confirmation dialog were verified. The UI test cancelled the dialog without resetting the operator's identity. Pairing transport tests used synthetic responses, including rejection and lost response; no live pairing was submitted. No server runtime files changed for this fix.

## Remaining acceptance

An additional opt-in `PendingPairingDiagnosticTest` passed on the physical phone. It exported only the existing pending key's public SPKI fingerprint/PEM, verified unchanged preferences and key aliases, and did not access the outbox or private key. The host independently verified the DER SPKI hash. See `PENDING-KEY-DIAGNOSTIC.md`. This required only a temporary test APK; the delivered application APK is unchanged.

No production deployment, real bank payment, overnight/screen-off reliability acceptance, emulator acceptance, or hardware-attestation approval was performed. The local backend verifier was a stub when reviewed; subsequent operator changes on the server have not been verified. See `BACKEND-CONTRACT.md`; the three server patches alone do not enable trusted automatic confirmation. Approved bank parser samples/source identities, controlled TLS rotation, external release signing and the physical acceptance checklist in `../app.md` remain required. This artifact is a tested debug build, not production release approval.
