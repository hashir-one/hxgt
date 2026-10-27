# Staging pending pairing public key diagnostic

The opt-in `PendingPairingDiagnosticTest` runs only against `in.hxdev.capture.debug` and refuses a paired identity or a key not in the submitted `sending` state. It reads the alias from the existing `capture` preferences, obtains that alias's certificate chain from Android Keystore and exports the leaf public key as PEM and SHA-256 of DER SubjectPublicKeyInfo (64 lowercase hex characters). This uses the same alias and public material as the pairing request. It does not access private key material, generate keys, initialize the repository, access the outbox, or submit network requests. It checks that preferences and the set of key aliases remain unchanged.

Build `:app:assembleDebugAndroidTest`, install the test APK alongside the existing debug app using `adb install -r -t`, then explicitly run:

```text
adb shell am instrument -w -e class in.hxdev.capture.PendingPairingDiagnosticTest -e exportPendingPublicKey true in.hxdev.capture.debug.test/androidx.test.runner.AndroidJUnitRunner
```

Afterward uninstall only `in.hxdev.capture.debug.test`, never the main app. No replacement application APK is needed for this diagnostic.

## Historical verification

The diagnostic passed on the connected iQOO on 25 October. Its public output was delivered privately to the operator, and its DER SPKI hash was independently checked on the host. No pairing submission or server policy change was made. Device-specific outputs are not included in source control.

## Retry limitations of the previously installed build

The newer staging source has a separate prepare/review/submit journal; see `STAGING-PAIRING.md`. The diagnostic below describes the legacy pending alias and is not a selector for the newer staging journal. Staging public keys are exposed through the staging UI.

The current app cannot resubmit a pending key, even while its QR token remains valid. `CaptureRepository.pair()` refuses when an alias or device UUID exists, and the token/request is not persisted for retry. This diagnostic does not change that behavior. Same-token retry would require an explicit implementation that verifies the original challenge/key binding and server token state; it is not provided here.

A new QR token requires a new challenge and new key. The staging exception must then be updated with that new pending key's fingerprint. Do not treat this exported fingerprint as a permanent device identifier. This operation did not prepare a fresh pairing or clear existing state/evidence.

The proposed maximum of 8 calendar months must be checked against the server's exact age calculation: 1 February to 25 October is 8 months and 24 days. A strict expiry at patch date plus 8 months would fail on 25 October, whereas a month-bucket policy may differ. Do not assume the exception passes until the server evaluates it.
