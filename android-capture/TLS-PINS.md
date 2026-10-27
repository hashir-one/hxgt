# Configure TLS pins for pay.hxdev.in

Pins are public SHA-256 hashes of DER SubjectPublicKeyInfo, prefixed with `sha256/`. They are compiled into the Android app, not stored in Laravel's `.env`. The app requires at least two distinct pins: a current server key and an independent backup key the operator can deploy. Private keys never go in the APK, repository, or chat.

Configured on 25 October 2026 in `gradle.properties` for debug builds:

```text
ACTIVE_PIN=sha256/9AyCfKL5oIufGR+XaBW/Znp2YJiigQyUoNLsTTzcApM=
BACKUP_PIN=sha256/73UjDawx/XEskEvxZTvsR7z3Sso+cVE/KpfmXkSnOsE=
```

The active pin matches the live server's trusted TLS 1.3 certificate. The backup hash was supplied by the operator after generating an encrypted independent key; backup certificate issuance and rotation have not yet been tested. Release builds still require `HX_TLS_PINS` explicitly. The instructions below remain the procedure for future setup/rotation; do not regenerate or overwrite your existing backup unnecessarily.

These steps assume your own Linux server terminates public HTTPS, as confirmed by the operator. Run the OpenSSL commands in **Bash on that server**, not Windows PowerShell (older PowerShell versions can corrupt binary pipelines). If a proxy/CDN terminates HTTPS, its public-facing certificate must be considered instead.

## 1. Find and hash the current certificate

```bash
sudo certbot certificates
```

Find the certificate covering `pay.hxdev.in`. Its name might have a suffix or be a wildcard name. Replace the example path below with its actual certificate path. `cert.pem` or the leaf-first `fullchain.pem` both work.

```bash
set -o pipefail
active_hash=$(sudo openssl x509 -in /etc/letsencrypt/live/pay.hxdev.in/cert.pem -pubkey -noout |
  openssl pkey -pubin -outform DER |
  openssl dgst -sha256 -binary |
  openssl base64 -A) && printf 'ACTIVE_PIN=sha256/%s\n' "$active_hash"
```

Verify that this is the certificate actually presented by the public server, with trusted CA and hostname verification enabled:

```bash
served_hash=$(openssl s_client -connect pay.hxdev.in:443 -servername pay.hxdev.in \
  -tls1_3 -verify_return_error -verify_hostname pay.hxdev.in </dev/null |
  openssl x509 -pubkey -noout |
  openssl pkey -pubin -outform DER |
  openssl dgst -sha256 -binary |
  openssl base64 -A) && printf 'SERVED_PIN=sha256/%s\n' "$served_hash"
```

The two hashes must match. Stop if either command fails or TLS verification fails. Check every public backend/key variant if the hostname serves multiple RSA/ECDSA certificates or load balancers; include every legitimate active key in the app's comma-separated list.

## 2. Create an independent backup key

Use an operator-controlled secure machine/directory outside the web root and repository. This creates a new encrypted key and does not replace the live server key. Enter a strong passphrase when prompted and retain it securely.

```bash
umask 077
backup_dir=$(mktemp -d "$HOME/hx-tls-backup.XXXXXX")
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 \
  -aes-256-cbc -out "$backup_dir/pay-backup-key.pem"
backup_hash=$(openssl pkey -in "$backup_dir/pay-backup-key.pem" -pubout -outform DER |
  openssl dgst -sha256 -binary |
  openssl base64 -A) && printf 'BACKUP_PIN=sha256/%s\n' "$backup_hash"
printf 'Keep the encrypted backup key securely at: %s\n' "$backup_dir"
```

The backup pin must differ from the active pin. Keep an encrypted offline backup of the key and a tested deployment procedure. A hash alone cannot be deployed. For rotation you must obtain a publicly trusted certificate for `pay.hxdev.in` using this exact backup key; a newly generated unrelated key will not match. A backup key does not need to be served now, but its future issuance/deployment must be tested before production acceptance.

## 3. Compile the pins into the app

On your Windows development PC, in `android-capture`, replace the placeholders with the two complete `sha256/...=` values. No spaces around the comma:

```powershell
.\gradlew.bat :app:assembleDebug "-PhxDebugPins=sha256/ACTIVE_BASE64,sha256/BACKUP_BASE64"
& "$env:LOCALAPPDATA\Android\Sdk\platform-tools\adb.exe" install -r app/build/outputs/apk/debug/app-debug.apk
```

For a signed release, also configure the external signing settings described in `README.md`:

```powershell
$env:HX_TLS_PINS = 'sha256/ACTIVE_BASE64,sha256/BACKUP_BASE64'
.\gradlew.bat :app:assembleRelease
```

`hxDebugPins` controls debug builds; `HX_TLS_PINS` controls release builds. The existing unconfigured APK cannot gain pins through a server setting; it must be rebuilt/reinstalled. Public pin strings may be shared for building. Do not share private key files or passphrases.

## 4. Plan renewals before enabling live use

Certbot normally generates a new key on renewal, which changes the pin. For a stable pin between deliberate rotations, Certbot 2.3+ supports updating this specific certificate's renewal options:

```bash
sudo certbot reconfigure --cert-name pay.hxdev.in --reuse-key
```

Use the actual certificate name from `certbot certificates`. This performs a staging renewal and saves the option on success. Confirm success and keep automatic renewal enabled. If your provider uses another ACME client, configure its equivalent or coordinate each new key with an app update. Key reuse preserves the pin across certificate renewal; it is not a replacement for planned key rotation.

For a planned rotation, distribute an APK accepting active A and backup B, confirm installation on all dedicated phones, then deploy a valid certificate using B. Next distribute an APK with B plus a fresh backup C. Do not replace the live key with an unpinned key or enable a CDN without coordinating the APK. Always retain CA/hostname checks and test the rotation on a controlled endpoint.

## Scope

This guide does not alter the running server. TLS pins establish the app's trust in the server; the server's Android hardware-attestation verifier and the remaining release acceptance checks are separate work described in `BACKEND-CONTRACT.md`. QR camera scanning works locally without pins; network pairing remains gated.

References: [OkHttp 4.12 CertificatePinner](https://github.com/square/okhttp/blob/parent-4.12.0/okhttp/src/main/kotlin/okhttp3/CertificatePinner.kt), [OpenSSL pkey](https://docs.openssl.org/master/man1/openssl-pkey/), [OpenSSL genpkey](https://docs.openssl.org/master/man1/openssl-genpkey/), [Certbot renewal configuration](https://eff-certbot.readthedocs.io/en/stable/using.html#modifying-the-renewal-configuration-of-existing-certificates).
