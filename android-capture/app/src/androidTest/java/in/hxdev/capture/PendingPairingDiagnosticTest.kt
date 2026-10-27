package `in`.hxdev.capture

import android.content.Context
import android.os.Bundle
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.security.KeyStore
import java.security.MessageDigest
import java.util.Base64
import java.util.Collections

/** Explicitly opted-in staging diagnostic: reads only the pending key's public material. */
class PendingPairingDiagnosticTest {
    @Test fun exportPendingPublicKey() {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        assumeTrue(InstrumentationRegistry.getArguments().getString("exportPendingPublicKey") == "true")
        check(BuildConfig.DEBUG && instrumentation.targetContext.packageName == "in.hxdev.capture.debug")
        val prefs = instrumentation.targetContext.getSharedPreferences("capture", Context.MODE_PRIVATE)
        val before = prefs.all.toMap()
        check(prefs.getString("device", null) == null) { "A paired device exists; refusing to export it as a pending key" }
        check(prefs.getString("pair_state", null) == "sending") { "No submitted pending pairing" }
        val alias = checkNotNull(prefs.getString("alias", null)) { "No pending pairing alias" }
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val aliasesBefore = Collections.list(store.aliases()).toSet()
        check(store.containsAlias(alias)) { "Pending key is unavailable" }
        val chain = checkNotNull(store.getCertificateChain(alias)) { "Pending attestation chain is unavailable" }
        check(chain.isNotEmpty())
        // This is the same alias and leaf public key used by KeyVault.publicPem/chain in pair().
        // Never call getKey(), generate a key, send a request, or access the outbox.
        val publicKey = store.getCertificate(alias).publicKey
        check(publicKey.format == "X.509") { "Expected DER SubjectPublicKeyInfo" }
        val spki = publicKey.encoded
        assertArrayEquals(chain.first().publicKey.encoded, spki)
        val fingerprint = MessageDigest.getInstance("SHA-256").digest(spki)
            .joinToString("") { "%02x".format(it.toInt() and 0xff) }
        check(fingerprint.matches(Regex("[0-9a-f]{64}")))
        val pem = "-----BEGIN PUBLIC KEY-----\n" +
            Base64.getMimeEncoder(64, byteArrayOf(10)).encodeToString(spki) +
            "\n-----END PUBLIC KEY-----"
        check(prefs.all == before) { "Pairing preferences changed during diagnostic; retry when idle" }
        check(Collections.list(store.aliases()).toSet() == aliasesBefore) { "Key aliases changed during diagnostic" }
        instrumentation.sendStatus(0, Bundle().apply {
            putString("pending_spki_sha256", fingerprint)
            putString("pending_public_key_pem", pem)
            putString("pending_state", "sending; no device UUID; leaf SPKI matches current alias")
            putString("preservation", "Preferences and key alias set unchanged; no outbox access; no private key access")
        })
    }
}
