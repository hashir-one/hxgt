# Pending-key recovery API — hx-pair-recovery-v1

Implemented 27 October 2026. **Deployed to `https://pay.hxdev.in` (staging) on
29 October 2026 at 16:43 UTC**, runtime commit `b6c94d8`: recovery migration applied,
all web/queue/scheduler services restarted, public endpoint checks passed. See
[the deployment record](ANDROID-HANDOFF.md#gateway-deployment-completed--29-october-2026).
This supersedes earlier missing-API and pending-deployment notes. Physical phone
recovery has not yet been performed. Other environments still require the
coordinated migration/restart procedure below.

## Purpose and scope

Resolve the registration of one retained Android Keystore P-256 public key. This
works without the original QR token, device UUID, or a trustworthy local outcome
receipt. The owner authorizes the exact public key; the phone proves possession
of its private key. Merely knowing a fingerprint or possessing an owner session
cannot resolve or close the key.

Scope is **`key_all_pairings`**, across all tokens for that key, including expired
and consumed tokens. This deliberately does not pretend to identify a lost token.
If any retained device record is bound to the key, it is a registration even if
revoked or never attested. Otherwise the proof permanently closes the key to all
future registrations. A later fresh pairing must use a new key and, in staging,
its newly approved SPKI exception pin.

The operation does not promote attestation, reactivate/revoke a device, consume a
pairing token, approve a migration, change patch policy, or modify phone evidence.
The app must preserve old keys, UUID associations and unresolved outbox rows.

## 1. Owner authorizes a challenge

Use the merchant browser panel → Devices → **Recover an uncertain pairing**.
Export the **existing pending public-key PEM** from the phone; do not generate a
new key. The browser flow accepts that PEM and a fresh owner email code and shows
challenge JSON to transfer to the phone.

The same panel routes support JSON for an already authenticated owner browser:

```http
POST /panel/devices/recovery/otp
Accept: application/json
X-CSRF-TOKEN: <browser CSRF token>
Cookie: <authenticated owner session>
```

No body. Response: `202 {"status":"code_sent"}`. The OTP purpose is
`recover_device_key`, separate from login, pairing and dispute codes. The session
holds its challenge ID; a subsequent request must use the same owner session.
Rate limit: 3 requests per 5 minutes.

```http
POST /panel/devices/recovery/challenges
Accept: application/json
Content-Type: application/json
X-CSRF-TOKEN: <browser CSRF token>
Cookie: <same owner session>
```

```json
{
  "public_key": "-----BEGIN PUBLIC KEY-----\n<base64 DER SPKI>\n-----END PUBLIC KEY-----\n",
  "otp": "123456"
}
```

Requires an active merchant, enabled owner, required second factor, and fresh
single-use recovery OTP. Staff/other roles cannot issue grants. `public_key` is
bounded to 8192 characters and must parse as an EC P-256 public key. The server
canonicalizes it and computes lowercase SHA-256 of DER SubjectPublicKeyInfo.
Rate limit: 5 requests per minute.

HTTP `201` challenge response (illustrative placeholders):

```json
{
  "protocol": "hx-pair-recovery-v1",
  "recovery_id": "<UUID>",
  "origin": "https://pay.hxdev.in",
  "method": "POST",
  "path": "/api/device/v1/pair/recovery",
  "scope": "key_all_pairings",
  "action": "resolve_and_close_if_absent",
  "merchant_id": "<merchant public UUID>",
  "spki_sha256": "<64 lowercase hex characters>",
  "nonce": "<64 lowercase random hex characters>",
  "expires_at": "<ISO 8601 UTC timestamp>",
  "expires_unix": 1793091600,
  "signing_payload": "<exact canonical string below>"
}
```

The challenge lasts 300 seconds. `origin` is the configured HTTPS
`gateway.device_domain`, never an incoming Host/forwarded header. Challenge
issuance does not look up the device or close its key. Do not put challenge JSON,
OTP codes, pairing tokens or signatures in Git or application logs.

## 2. Phone verifies the challenge and signs with its retained key

Before signing, require the exact protocol, scope, action, method and path below.
Require the origin to equal the app's configured gateway origin; never follow an
arbitrary challenge URL. Require the merchant public UUID to match the intended
owner-authorized merchant, or display it for confirmation when this legacy phone
has no saved merchant identity. Compare the SPKI fingerprint with the retained
alias's actual leaf-certificate public key. Validate the UUID/nonce/fingerprint
formats, that ISO expiry and Unix expiry agree, and freshness. Do not silently
switch to another alias. Use phone credential confirmation for this operation.

Reconstruct the following **11 UTF-8 lines**, joined with one LF (`0x0a`), with
**no trailing newline**. Validate that the reconstructed bytes equal the supplied
`signing_payload`; do not blindly sign an arbitrary string from a QR or clipboard.

```text
hx-pair-recovery-v1
POST
<origin>
/api/device/v1/pair/recovery
<recovery_id>
<merchant_id>
<spki_sha256>
key_all_pairings
resolve_and_close_if_absent
<nonce>
<expires_unix as base-10 integer>
```

Sign these bytes using Android `Signature.getInstance("SHA256withECDSA")` with
the **retained pending private key**. Encode the resulting ASN.1 DER ECDSA
signature in standard Base64 without line wrapping. Do not use raw `r || s`,
Base64URL, hex, or hash the message yourself before `SHA256withECDSA`.

Persist the challenge, alias, fingerprint, canonical bytes and an in-progress
recovery state in the encrypted attempt journal **before** the network call.
This is a distinct recovery proof, not a `/pair` retry or a new attestation.

## 3. Submit the proof over the pinned HTTPS transport

```http
POST /api/device/v1/pair/recovery
Content-Type: application/json
Accept: application/json
```

```json
{
  "recovery_id": "<challenge recovery_id>",
  "signature": "<standard Base64 DER ECDSA signature>"
}
```

No owner cookie, OTP, pairing token, certificate chain, `X-Device-ID` or ordinary
`X-Device-*` request-signature headers are required here. Authentication is the
unexpired owner grant plus this proof of the exact key. Add this exact path to
Android `DeviceApi`'s allowlist and explicitly route it through pending-key proof,
not the registered-device transport. Keep TLS pinning, no redirects and bounded
JSON response parsing. Rate limit: 10 requests per minute.

HTTP `200` for an authoritative negative:

```json
{
  "protocol": "hx-pair-recovery-v1",
  "recovery_id": "<same challenge UUID>",
  "merchant_id": "<same merchant public UUID>",
  "spki_sha256": "<same key fingerprint>",
  "scope": "key_all_pairings",
  "outcome": "not_registered_closed",
  "registration": null,
  "resolved_at": "<ISO 8601 UTC timestamp>",
  "key_closed": true
}
```

This means the key has no retained registration and its permanent fence committed
before the response. No in-flight/future pairing using that key can subsequently
commit. Store a new durable server recovery receipt associated with the legacy
alias/fingerprint. The app can then permit **new-key preparation**, preserving
old key/history/outbox and enforcing existing evidence/identity-transition guards.
Do not resubmit `/pair` with the closed key. Do not convert old evidence to the
new identity or mark it acknowledged.

HTTP `200` for an existing registration has the same envelope, with:

```json
{
  "outcome": "registered",
  "key_closed": false,
  "registration": {
    "device_id": "<existing device public UUID>",
    "status": "offline",
    "attestation_status": "verified",
    "revoked_at": null,
    "can_activate": true
  }
}
```

`status` is the **current** record status, not necessarily `offline`.
`can_activate=true` requires an unrevoked, verified record with status
`offline`, `online`, or `degraded`. It does not establish listener health/payment
readiness. Persist the UUID/key recovery receipt, then use the existing explicit
activation/evidence guards. Verify normal signed heartbeat status afterward.

A historical unverified registration also returns `outcome=registered`, but its
actual `attestation_status` and `can_activate=false`. It must not become verified
from recovery; retain it for the approved migration/reconciliation flow.

A revoked record returns `outcome=revoked`, `key_closed=false`, its actual record
fields and `can_activate=false`. Never reactivate it, call it unregistered, or
reuse its key. Existing owner/admin replacement rules and outbox preservation
still apply. Cross-merchant registrations return a generic error without a UUID
or negative receipt.

Recovery receipts have their own validator. Do not feed these responses to the
normal `/pair` success validator expecting `status=offline`. Require the exact
challenge ID, merchant, fingerprint, protocol and scope, consistent outcome/
registration/key_closed values, and a valid timestamp. Treat unknown/inconsistent
fields required for a decision as an uncertain recovery. Registration status is
a snapshot and can later change through normal administrative actions.

## Errors, replay and lost responses

Service errors use `{"error":"CODE"}`. Validation errors use Laravel's
`message`/`errors` shape. Browser authentication/MFA failures can redirect to
login/TOTP; they are not recovery responses.

| HTTP | Error | Meaning/action |
| --- | --- | --- |
| 401 | `RECOVERY_PROOF_INVALID` | Signature malformed or not valid for this exact challenge/key. No recovery result. |
| 404 | `RECOVERY_UNAVAILABLE` | Unknown challenge or inaccessible registration. No negative conclusion. |
| 403 | `RECOVERY_UNAVAILABLE` | Grant owner disabled/deleted/reassigned, no longer owner, or merchant inactive. |
| 409 | `RECOVERY_ALREADY_USED` | This challenge was already consumed. Its outcome is not returned by replay. |
| 409 | `RECOVERY_UNAVAILABLE` | Configured device origin changed since authorization. |
| 410 | `RECOVERY_EXPIRED` | Challenge expired, including while waiting for a database lock. |
| 422 | validation error | Invalid UUID/signature/request or invalid owner public key/OTP. |
| 429 | rate limit | Wait as directed; preserve pending state. |
| 503 | `RECOVERY_NOT_CONFIGURED` | Device domain is not a valid HTTPS origin. |
| 503 | `RECOVERY_STATE_INVALID` | Registry/device key mismatch. Requires operator investigation. |
| 5xx / timeout / malformed JSON | — | Outcome is uncertain. Preserve the key and encrypted attempt journal. |

The nonce is single-use. An identical proof is **not idempotently replayed**.
If the `200` response is lost, obtain another owner-authorized challenge for the
**same retained key**, then sign and resolve again. A prior permanent closure
returns `not_registered_closed` again; a prior registration returns its current
record. No old pairing token is needed. A replay/expiry/error alone never permits
reset, replacement, deleting a key, or assuming non-registration.

Once closed, a valid `/pair` attempt reaching the registration transaction fails
with `422 {"message":"PAIRING_KEY_CLOSED"}`. Earlier token/attestation failures
may still return their existing codes. Only a validated recovery receipt is the
app's authoritative negative result.

## Atomicity and rollout

`device_key_states` is keyed by canonical DER SPKI SHA-256 and stores either a
registered device reference or a permanent closure timestamp. Recovery and
pairing first insert-on-conflict and lock the same key row inside their database
transactions. Registration binds the row, creates the device, consumes the token
and performs migration revocation in one commit. Recovery checks authorization,
proof, expiry and existing registration before consuming the nonce and either
returning that record or closing the key. Exceptions roll back all these writes.

Lock order: key → merchant → owner/recovery (recovery path) or pairing (pair path)
→ device. Trust verification runs before the pairing transaction; a negative
recovery can commit during that work and the final key check still rejects it.
No negative is produced from a bare unlocked lookup. Unique-row insertion also
serializes the first request when no key row exists yet.

The new migration is
`2026_10_27_082400_create_pending_key_recovery_tables.php`. It indexes all existing
parseable P-256 device keys, including unverified/revoked records and alternative
PEM whitespace, without changing their identity/trust fields. Duplicate canonical
keys fail migration rather than choosing a record. Unsupported legacy key types
cannot use this P-256 endpoint. The device reference restricts deletion so a
historical registration cannot disappear and later become a negative receipt.

Deploy all pairing writers together:

1. Back up the database and place the service in maintenance; drain/stop every
   old web/queue/scheduler process able to execute pairing writes.
2. Deploy this code and apply `php artisan migrate --force` against the intended
   environment. Investigate migration errors; never reset/seed the merchant DB.
3. Refresh normal configuration/view caches and restart **all** workers with this
   version before reopening traffic. Mixed old/new pairing writers are unsafe:
   old code does not check key closures.
4. Verify routes and owner challenge/proof flow, then enable the Android UI.

Do not prune/clear `device_key_states`, restore a pre-fence backup while allowing
old requests, or roll back to a pairing writer that ignores it. Migration `down()`
intentionally refuses to erase permanent fences. Retention/restore changes need
an explicit reconciliation plan. Recovery does not require changing the existing
attestation configuration. The initial source push did not deploy the application;
the subsequent 29 October staging deployment is recorded at the top of this file.

## Verification

The feature suite covers owner OTP/session/MFA, proof bindings, nonce replay and
expiry, disabled/reassigned owners, merchant isolation, historical unverified and
revoked records, canonical-key backfill, and late pairing after closure. All
feature tests use isolated SQLite `:memory:` and fake external trust endpoints.
Validation on 27 October: full server suite **232 tests / 1,566 assertions**
passed; the expanded recovery-only suite **29 tests / 185 assertions** passed,
including protocol/method tampering and rollback after an injected commit failure.

`tests/Integration/PendingKeyRecoveryConcurrencyTest.php` is opt-in and uses only a
disposable PostgreSQL cluster at a `/tmp/hx-recovery-pg-*` Unix socket, port 55439,
database `hx_recovery_test`. It creates/drops its own random schema. Two forked
processes call the real pairing/recovery HTTP handlers; the test observes the
loser blocked on a PostgreSQL lock before releasing the winner. Both orderings
are covered, including first use of an absent registry row. Actual Java
attestation verification runs against a synthetic chain and fake Google trust.
Both PostgreSQL ordering tests passed: **2 tests / 21 assertions**.

```bash
APP_CONFIG_CACHE=/tmp/hx-recovery-test-config-absent.php APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= php artisan test --compact
HX_RECOVERY_PG_SOCKET=/tmp/hx-recovery-pg-20261027 APP_CONFIG_CACHE=/tmp/hx-recovery-test-config-absent.php APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= vendor/bin/phpunit tests/Integration/PendingKeyRecoveryConcurrencyTest.php --no-progress --fail-on-risky
```

`tests/Fixtures/AndroidAttestation/recovery_test.json` intentionally contains a
**synthetic test-only private key** paired with a synthetic certificate chain so
the test can prove possession and run the real verifier. It is generated from
upstream test utilities, never from the phone or any real signing identity. Never
load fixture roots into application trust. Exclude test fixtures from production
artifacts.
