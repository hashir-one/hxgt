# Staging pairing work in progress

The staged pairing implementation is local and has not yet been installed or validated on the physical phone. Do not treat the earlier delivered APK or earlier physical test results as verification of this new flow.

## Implemented locally

- Explicit debug build flag `hxStagingPairing=true`; release builds always disable the staging flow.
- Separate encrypted attempt journal, preserving previous attempts, keys, capture identity and unresolved evidence.
- Owner-authorized QR input plus its exact UTC expiry from the panel. The current QR format contains only URL and token, so expiry is entered separately. Server expiry/authorization checks remain authoritative.
- Phone credential confirmation before preparing a key. Preparation stores the immutable token/key/attestation request and exposes only public PEM and DER SPKI SHA-256. It makes no network request.
- Explicit submission after the operator configures the matching server exception. A definitive rejection permits the identical saved request to be retried while the token is valid. A fresh token creates a separate key/fingerprint.
- Timeout, cancellation, server error, ambiguous response or interrupted submission blocks both retry and replacement, including after token expiry.
- Successful server registration is stored separately. Explicit activation requires stopped capture and an empty unresolved outbox. Prior capture preferences are archived in the encrypted journal; old keys remain.

## Outstanding server contract

There is no known authenticated pending-key status/recovery endpoint. The app therefore cannot automatically resolve uncertain submissions. No local checkbox can override that block. A legacy attempt can proceed only when its saved result is an explicit known rejection, such as this phone's `ATTESTATION_REJECTED`; generic errors are insufficient.

On 27 October 2026, gateway main was updated to `3c152dc` with the attestation verifier and scoped staging vendor exception. Its owner-session pairing-status endpoint is for the web panel, not Android proof-of-key recovery. See `../docs/ANDROID-HANDOFF.md` for the current integration boundary.

The gateway agent should push its current verifier/policy code and a handoff describing the recovery route, request/response schema, authentication and proof-of-possession, authoritative outcomes, atomicity with in-flight pairing requests, expired/consumed token handling, and valid QR expiry format. A bare `not found` lookup is not enough if an earlier request can still commit afterward.

## Changed implementation files

- `app/build.gradle.kts`, `gradle.properties`
- `app/src/main/java/in/hxdev/capture/core/StagingPairing.kt` (new)
- `app/src/main/java/in/hxdev/capture/core/CaptureRepository.kt`
- `app/src/main/java/in/hxdev/capture/core/PairingFailure.kt`
- `app/src/main/java/in/hxdev/capture/MainActivity.kt`
- `app/src/androidTest/java/in/hxdev/capture/StagingPairingDeviceTest.kt` (new)

No server runtime files have been changed for this flow. The physical staging tests and UI verification remain required before publishing the updated APK.
