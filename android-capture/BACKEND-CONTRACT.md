# Current Android / gateway contract

Updated 27 October 2026 against gateway `3c152dc` and review `52dff7e`. See `../docs/ANDROID-ATTESTATION.md` for the server implementation and policy, and `../docs/ANDROID-HANDOFF.md` for coordination and remaining dependencies.

## Registration and migration

`POST /api/device/v1/pair` takes a fresh owner-authorized token, EC P-256 SubjectPublicKeyInfo PEM public key, and full leaf-first base64 DER attestation chain (3–8 certificates including the official root). The Keystore challenge is the 32 raw bytes of `SHA256(UTF8("hx-pair-v1\n" + token))`. Every new token requires a new key/chain.

The server verifier is implemented. It validates certificate paths, current official trust/revocation data, challenge/SPKI binding, package/APK signer, hardware key properties, verified boot and independent OS/vendor/boot patch requirements. The app installs on API 29+, but server policy requires Android 13+ and the configured patch minima/age. APK signer digests, TLS pins and pending-key SPKI fingerprints are different values.

Only this complete HTTP 201 response establishes registration:

```json
{"device_id":"<canonical lowercase UUID>","status":"offline","attestation_status":"verified"}
```

Missing/wrong fields leave the attempt uncertain. Verified registration is not healthy capture or an open payment gateway. Successful replacement revokes the previous server identity in the same transaction; local activation cannot defer that revocation. The Android implementation therefore requires stopped capture and zero unresolved outbox records **before submission and activation**. Preparing/exporting a separate pending key does not submit and can preserve unresolved evidence.

Attestation failures return bounded `error`, `code`, and optional sanitized patch `details`. An explicit HTTP 422 `ATTESTATION_REJECTED` for a locally recorded attempt can persist a definitive rejected receipt. Token unavailable, slot occupied, revoked migration, key already registered, timeout, interrupted submission, malformed success and unknown responses remain uncertain. A failed later request cannot disprove an earlier registration. General `capture.error` is UI feedback, not a pairing receipt.

Every new receipt is bound to attempt ID, token hash, key alias and DER SPKI fingerprint and encrypted locally. Old records without that provenance stay blocked, including historical ATTESTATION_REJECTED text and old journal decisions. A resolved pending attempt is archived before any permitted transition; keys are never deleted. Legacy reset cannot discard a paired identity. The same repository guards apply with staging disabled.

## Parser schema

Top-level: positive `version`, `source=kotak`. `rules` contains:

| Field | Contract |
|---|---|
| `schema` | `hx-credit-re2-v1` |
| `sender_ids` | Exact approved sender IDs matching `^[A-Z]{2}-KOTAKB(?:-[A-Z])?$` |
| `notification_packages` | Actual verified bank package IDs |
| `notification_signing_sha256` | JSON map from package to array of lowercase SHA-256 signing-certificate DER digests |
| `amount_pattern` | RE2 regex, exactly one capture group containing decimal amount |
| `reference_pattern` | RE2 regex, exactly one capture group containing 12-digit reference |
| `credit_pattern` | Positive credit classification regex |
| `reject_pattern` | Negative/ambiguous classification regex |

Regexes have no PCRE delimiters; lookaround/backreferences are unsupported. Patterns are capped at 500 characters, input at 8192; RE2 avoids catastrophic backtracking. Multiple distinct amounts/references reject the alert; missing reference remains null for server review. Fixed client exclusions also reject OTP, debit, failure, reversal, promotional and credit-card text. Real samples must validate patterns before activation. Synthetic test package/sender/certificate values are **not production bank configuration**.

## Recovery API dependency

`GET /panel/devices/pairings/{numeric_pairing_id}/status` is an owner-session browser endpoint. It does not prove the pending SPKI's registration or non-registration and is never used as an Android recovery API.

No pending-key recovery API is implemented. A future contract must require owner authorization and a fresh nonce bound to origin, purpose, attempt/token and SPKI, plus proof of possession. Results must identify the authoritative bound registration and order atomically against in-flight commits. A negative snapshot or expiry cannot authorize replacement; a terminal negative needs a fence against a later commit. Uncertain attempts stay blocked until this contract exists.

Re-attestation/rotation and any old-evidence reconciliation also need explicit server contracts. Old records keep their original UUID, ciphertext, signal ID and timestamps. They are never moved to the replacement identity, marked acknowledged locally or re-signed as new-device evidence.

## Remaining acceptance

The scoped staging vendor exception and verifier source exist; a Git push is not deployment proof. A newly prepared key requires its own exception pin and the server's unchanged policy still applies. Physical successful attestation/registration, verified sender/package/signer identities, real notification samples, tracked payments/webhooks, release signing, TLS rotation and overnight reliability remain separate acceptance requirements. Synthetic tests and debug builds do not establish production readiness.
