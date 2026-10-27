# Backend integration and remaining trust work

**27 October 2026:** gateway commit `3c152dc` supersedes the stub described in this historical implementation note. The server verifier and staging policy are now implemented; see `../docs/ANDROID-ATTESTATION.md` and `../docs/ANDROID-HANDOFF.md`. Physical attestation acceptance and an Android proof-of-key recovery API remain unverified/unavailable respectively.

## Implemented

Migration pairing now rejects `MIGRATION_ATTESTATION_REQUIRED` (422) unless attestation is verified, before revoking the old phone or consuming its token. The check runs in the locked transaction. Initial pairing remains pending under the stub; chain submission never becomes trust.

The existing admin parser form now supports Android rules as drafts, including credit/reject classification and verified bank APK certificate identities. Existing legacy rules stay compatible with the server. The signed parser endpoint returns the new fields unchanged. No new native API is assumed.

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

## Required server attestation verifier — still a release dependency

The existing `AttestationVerifier` remains unimplemented. Its `submitted` result cannot authorize evidence. Deliver a reviewed verifier before live use, with a typed contract:

```text
verify(chainBase64Der, submittedSpkiPem, pairingToken, policySnapshot, now)
  -> Verified(evidenceDigest, policyVersion, rootId, package, signer, patchLevels)
   | Rejected(reasonCode)
   | Unavailable(reasonCode)
```

Policy contains environment-specific package/APK signer allowlists, current official Android root set/version, revocation freshness, hardware security levels, minimum OS and OS/vendor/boot patch policy. Missing/stale policy fails closed.

1. Validate an unconsumed, approved token without consuming it. Bound DER chain length (8), certificate size (16 KiB), total bytes and ASN.1 nesting. Reject malformed/trailing encodings. Never fetch certificate-supplied URLs.
2. Verify X.509 path, signatures, constraints, validity and revocation against maintained official Android roots/status sources, including root rotations/RKP. Uploaded self-signed roots confer no trust. Trust-source failure cannot return verified.
3. Parse the Android attestation extension with a reviewed ASN.1 implementation, respecting hardware/software authorization placement and attestation-key delegation. Bind the attested signing key's canonical SPKI to the submitted public PEM. Require EC P-256, signing purpose, SHA-256 and generated hardware origin.
4. Constant-time compare the challenge against the **32 raw bytes** of `SHA256(UTF8("hx-pair-v1\n" + token))`. Validate attested application package and APK signer digest against release policy. Model/version metadata and client `KeyInfo` are not server trust evidence.
5. Require TEE/StrongBox security, locked verified boot and acceptable OS/security patch levels. Keep debug signer/environment separate from live policy. Record bounded reason codes and policy/evidence digests without tokens or customer text.
6. Only Verified may bind a live device. In the merchant/pairing transaction recheck expiry/approval/slot/key uniqueness, create replacement, revoke old key and consume token atomically. Failure leaves old phone/token untouched. Add concurrent migration/replay regression tests.

Negative cases: wrong challenge/key/package/signer, invalid/rootless/revoked/expired chain, malformed ASN.1, software security, unlocked boot, outdated patches, stale/unavailable trust source, token replay/expiry, duplicate key, transaction rollback. A positive case requires the actual iQOO's release-app hardware chain; mock verification is not proof.

References: [Android key-attestation verification](https://developer.android.com/privacy-and-security/security-key-attestation), [AOSP schema](https://source.android.com/docs/security/features/keystore/attestation). Use maintained root/revocation sources linked there, not a historical root copied into this specification.

## Future recovery/refresh protocol

Lost response recovery must require owner authorization and a fresh expiring nonce bound to the pending key. The existing key signs origin/purpose/nonce; only its bound UUID may be returned. No generic key lookup or recovery route is shipped.

Re-attestation requires a fresh challenge and newly attested key, proof of both current and replacement keys, server policy verification and atomic cutover under merchant/device locks. Drain or explicitly preserve old-key evidence before switching; never move evidence to another merchant. Reject old signatures after cutover and audit the change. An original certificate is not fresh attestation.

## Release inputs still absent

Approved real bank samples/sender/package/signing identities; implemented and deployed server verifier; release APK signing identity; tested TLS rotation; tracked-payment and overnight reliability evidence. The operator supplied active and independent backup public-key pins on 25 October 2026; they are configured in the debug build, and the active pin matches the live TLS certificate. Backup issuance/deployment and rotation remain untested. No bank transfer, server deployment, native owner API, FCM delivery or production readiness is claimed.
