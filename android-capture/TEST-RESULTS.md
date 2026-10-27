# Build and test results

## Current source — 27 October 2026

Staging prepare/review/submit source and test APK build passed, with 14 JVM tests and lint at 0 errors / 29 warnings. Gateway Kotak SMS fixtures passed through actual RE2/J. **The new staged flow has not been run on the physical phone or installed; ADB has no device.** No new APK has been published for this flow. See `../docs/ANDROID-HANDOFF.md`. All APK hashes and phone results below describe the earlier delivered build.

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
