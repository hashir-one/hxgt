# Staging pairing work in progress

The staged implementation has been installed in place and its focused tests run on the physical iQOO. See TEST-RESULTS.md for the current measured results; synthetic server responses do not establish actual registration.

## Implemented locally

- Explicit debug build flag `hxStagingPairing=true`; release builds always disable the staging flow.
- Separate encrypted attempt journal, preserving previous attempts, keys, capture identity and unresolved evidence.
- Owner-authorized QR input plus its exact UTC expiry from the panel. The current QR format contains only URL and token, so expiry is entered separately. Server expiry/authorization checks remain authoritative.
- Phone credential confirmation before preparing a key. Preparation stores the immutable token/key/attestation request and exposes only public PEM and DER SPKI SHA-256. It makes no network request.
- Explicit submission after the operator configures the matching server exception. A definitive rejection permits the identical saved request to be retried while the token is valid. A fresh token creates a separate key/fingerprint.
- Timeout, cancellation, server error, ambiguous response or interrupted submission blocks both retry and replacement, including after token expiry.
- Successful server registration is stored separately. Both submission and explicit activation require stopped capture and an empty unresolved outbox because registration can revoke the old server key immediately. Prior capture preferences are archived in the encrypted journal; old keys remain.

## Outstanding server contract

There is no known authenticated pending-key status/recovery endpoint. The app therefore cannot automatically resolve uncertain submissions. No local checkbox can override that block. A legacy attempt can proceed only with a durable encrypted receipt bound to that attempt, token hash and SPKI. Old general error text (including this phone's `ATTESTATION_REJECTED`) is insufficient; the existing phone attempt remains blocked. Older journal decisions without version-2 provenance also remain blocked.

On 27 October 2026, gateway main was updated to `3c152dc` with the attestation verifier and scoped staging vendor exception. Its owner-session pairing-status endpoint is for the web panel, not Android proof-of-key recovery. See `../docs/ANDROID-HANDOFF.md` for the current integration boundary.

The remaining gateway work is a recovery route with owner authorization, proof of key possession, authoritative outcomes, atomic ordering against in-flight requests and expired/consumed token handling. A bare `not found` lookup is not enough if an earlier request can still commit afterward.

## Changed implementation files

- `app/build.gradle.kts`, `gradle.properties`
- `app/src/main/java/in/hxdev/capture/core/StagingPairing.kt` (new)
- `app/src/main/java/in/hxdev/capture/core/CaptureRepository.kt`
- `app/src/main/java/in/hxdev/capture/core/PairingFailure.kt`
- `app/src/main/java/in/hxdev/capture/MainActivity.kt`
- `app/src/androidTest/java/in/hxdev/capture/StagingPairingDeviceTest.kt` (new)

No server runtime files have been changed for this flow. Current verification and deployment status are in TEST-RESULTS.md and the gateway handoff.

## Identity transition barrier

Lock order is pairing mutex → identity barrier → staging journal mutex → database. Capture/fault insertion, backfill, drain, heartbeat/rule state, session changes, reset and activation share the barrier. Listeners capture an identity generation before launching asynchronous work; callbacks queued before a successful transition cannot write under the new identity. An in-flight callback finishes before the zero-pending check can run. Reset archives a definitively rejected attempt; activation archives previous capture preferences and can recover an interruption after the atomic identity commit.
