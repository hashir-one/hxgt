# HX Pay Capture for Android

Implementation of `../app.md`. Target phone: **iQOO Neo 9 Pro (I2304), OriginOS 6, Android 16 / API 36**. Minimum Android 10 / API 29, compile/target SDK 36. Version **0.1.0 (1)**.

**27 October update:** the source includes the staging prepare → fingerprint → submit flow described in [STAGING-PAIRING.md](STAGING-PAIRING.md). It has not yet been tested or installed on the physical phone. Gateway integration status and remaining work are recorded in [../docs/ANDROID-HANDOFF.md](../docs/ANDROID-HANDOFF.md). The server requires Android 13 or newer even though the app can install on API 29. The legacy recovery instructions below apply only with staging pairing disabled.

## Build and install

Open this directory in Android Studio. Install Android SDK/platform/build tools 36 and JDK 17+. Gradle 9.1.0 has a pinned distribution checksum; compatible dependency versions are in `gradle/libs.versions.toml`.

```powershell
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'
$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
.\gradlew.bat :app:lintDebug :app:testDebugUnitTest :app:assembleDebug :app:assembleDebugAndroidTest
& "$env:ANDROID_HOME\platform-tools\adb.exe" -s YOUR_SERIAL install -r app/build/outputs/apk/debug/app-debug.apk
& "$env:ANDROID_HOME\platform-tools\adb.exe" -s YOUR_SERIAL shell am start -n in.hxdev.capture.debug/in.hxdev.capture.MainActivity
```

On macOS/Linux use `./gradlew`, set local SDK/JDK paths, and restore its executable bit if a Windows ZIP removed it. Android Studio can set SDK location in the ignored `local.properties`.

Debug ID is `in.hxdev.capture.debug`; release ID is `in.hxdev.capture`. **At the user's explicit request both default to `https://pay.hxdev.in`.** For an isolated test backend set `-PhxDebugOrigin=https://your-test-host.example`. Do not approve the debug signer on the live payment backend. No fabricated payment or fixture messages are included in the application APK.

## TLS and release signing

See [TLS-PINS.md](TLS-PINS.md) for server commands to derive the active pin, create an encrypted independent backup key, configure the APK and preserve pins during certificate renewal. Camera QR scanning works locally without these pins; sending a pairing request still requires them.

The operator supplied active and independently generated backup TLS pins on 25 October 2026. Their public hashes are saved as `hxDebugPins` in `gradle.properties`; the active pin was verified against the live hostname using trusted TLS 1.3. Debug builds now include both pins by default. Override with `-PhxDebugPins=sha256/ACTIVE,sha256/BACKUP` when rotating; for another origin also supply that origin's pins. The backup key remains with the operator, and backup deployment/rotation still requires testing. TLS 1.3, public CA and hostname validation remain mandatory; redirects and cleartext are rejected.

Release requires external environment variables `HX_TLS_PINS`, `HX_SIGNING_STORE`, `HX_SIGNING_STORE_PASSWORD`, `HX_SIGNING_ALIAS`, `HX_SIGNING_KEY_PASSWORD`, then `./gradlew :app:assembleRelease`. The build refuses release without signing/pins. No release key is generated or replaced, and secrets stay outside the repository. A debug APK is not a production release.

## iQOO setup

1. Use the dedicated merchant-owned phone and confirm its bank account. Do not mix unrelated accounts under a merchant identity.
2. In Setup read the disclosure and authorize capture. Grant SMS reception/backfill and notification display in context. Camera access is requested only for QR scanning.
3. Android/OriginOS may restrict sensitive settings for a sideloaded app. Review app info → restricted settings/permissions where available, and separately enable HX Pay in notification access. Installer restrictions can still block SMS access; a runtime prompt alone is not proof of access.
4. Review OriginOS autostart and app background/battery controls for HX Pay and the bank app. Labels vary by firmware. Keep the actual bank app signed in. An exemption or recent-app lock does not guarantee uptime.
5. Once pins and backend trust are ready, open the merchant Devices page, complete owner email verification, and generate a fresh QR. In the staging flow, enter its exact UTC expiry, prepare the key, share its public fingerprint, and submit only after the server exception is configured. The saved request/token is encrypted locally for explicit same-key retry. The legacy non-staging flow keeps tokens in memory only.
6. Start the visible capture session. The ongoing notification has a stop action. Trust, approved rules, both listeners, backfill, queue and fresh healthy heartbeats are required. Pairing alone is not readiness.
7. After reboot/force-stop, reopen HX Pay and resume the session. Measure overnight/screen-off behavior on the actual phone. No universal 24/7 execution guarantee is made.
8. Confirmed collections and owner decisions use the authenticated browser merchant panel. No native owner powers, FCM contract, or collection endpoint is invented.

## Data and recovery

The Room outbox encrypts immutable structured payloads with Keystore AES-256-GCM; device/record IDs are authenticated AAD. Signing uses a separate non-exportable P-256 Keystore key with the token-bound challenge. Metadata/fingerprints remain in the database; raw SMS/notifications never do. Backup/transfer is excluded.

A single process mutex coordinates service/WorkManager drains. Capture inserts are transactional and deduplicated. Each upload contains one signal, retains its original logical ID/body, and obtains a new request timestamp/nonce/signature. Review responses count as delivered evidence. 401/403 block identity pending explicit recovery; 409 conflicts are retained without looping. Fault episodes deduplicate per type/source/hour.

Acknowledged receipts expire after seven days; unresolved evidence is retained. At 10,000 unresolved records readiness is degraded while durable writes continue. Disk/key errors latch degradation. A full disk can prevent persistence; the app must not claim readiness, SMS requires backfill, and a missed notification may be unrecoverable.

SMS backfill starts at pairing time and uses a ten-minute overlap. It queries only approved senders, orders equal timestamps by provider ID, and advances its cursor only after processing. Event time is `date_sent` if present, otherwise provider `date`; live broadcasts use `SmsMessage.timestampMillis`. Validate equivalence on this phone with real samples. Notifications use `StatusBarNotification.postTime`; active replay deduplicates notification key plus extracted evidence, while dismissed history cannot be reconstructed. No bank transaction time is invented.

Do not clear app data to repair delivery. Recovery can refresh rules and retry compatible blocked records. A guarded fresh pairing requires the phone lock, stopped session and zero unresolved evidence; owner revocation/migration approval remains a separate server action. Old keys/receipts are preserved and never rebound to another merchant. Lost pairing responses require owner recovery because a proof-of-key recovery endpoint is not implemented.

After revoking a phone or an interrupted pairing, open **Setup → Prepare a new pairing**, confirm the phone lock, then generate and scan a fresh owner-approved QR. Setup detects a saved signing alias without a device ID and shows this recovery action instead of allowing repeated pairing requests that cannot succeed. Pending evidence or a running capture session blocks preparation. Pairing errors appear in a modal and retain an allowlisted server code (or HTTP status); the app never logs QR tokens or server response bodies. A timeout may have occurred after server registration, so check the merchant Devices page before replacing an identity.

## Verification

JVM tests cover parser/currency/source boundaries, malicious QR destinations, bounded regex execution, exact signing bytes, ECDSA fixtures and delivery policy. Device tests use isolated test preferences/databases and synthetic payloads, never bank SMS or a real transfer:

```powershell
$env:ANDROID_SERIAL = 'YOUR_SERIAL'
.\gradlew.bat :app:connectedDebugAndroidTest
```

`protocol-fixtures/signing.json` is public synthetic evidence generated using Java ECDSA and verified by Kotlin/PHP. The private key was never saved. Real hardware attestation, actual source samples and a tracked payment require separate acceptance.

See `BACKEND-CONTRACT.md`, `TEST-RESULTS.md`, and `../SERVER-UPDATE.md`. The physical acceptance checklist in `../app.md` remains the release gate.
