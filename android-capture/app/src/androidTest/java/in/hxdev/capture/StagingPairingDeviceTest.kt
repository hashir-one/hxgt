package `in`.hxdev.capture

import android.content.Context
import androidx.room.Room
import androidx.test.platform.app.InstrumentationRegistry
import `in`.hxdev.capture.core.*
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.first
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.io.IOException
import java.security.KeyStore
import java.time.Instant
import java.util.Collections
import java.util.UUID

class StagingPairingDeviceTest {
    private class Fixture {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val name = "stage-test-${UUID.randomUUID()}"
        val identity = context.getSharedPreferences(name, Context.MODE_PRIVATE)
        val journal = context.getSharedPreferences("$name-staging", Context.MODE_PRIVATE)
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val originalAliases = Collections.list(store.aliases()).toSet()
        val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
        val bodies = mutableListOf<String>()
        var response: () -> ApiResult = { ApiResult(422, JSONObject().put("error", "ATTESTATION_REJECTED")) }
        val transport = object : DeviceTransport {
            override val configured = true
            override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult {
                assertEquals("/api/device/v1/pair", path)
                bodies += String(body, Charsets.UTF_8)
                return response()
            }
        }
        val repo = CaptureRepository(context, db, transport, name)
        var time = Instant.now()
        val flow = StagingPairing(journal, identity, repo.vault, transport, { time })
        fun expiry() = time.plusSeconds(240).toString()
        suspend fun close() {
            repo.scope.coroutineContext[Job]!!.cancelAndJoin()
            db.close()
            identity.edit().clear().commit(); journal.edit().clear().commit()
            // Isolated test execution: delete only the staging/test aliases created by this fixture.
            (Collections.list(store.aliases()).toSet() - originalAliases)
                .filter { it.startsWith("hx-staging-") || it.startsWith("stage-test-") }
                .forEach { store.deleteEntry(it) }
        }
        fun oldPending(error: String): String {
            val alias = "stage-test-${UUID.randomUUID()}"
            repo.vault.generate(alias, "Z".repeat(48))
            identity.edit().putString("alias", alias).putString("pair_state", "sending").putString("error", error).commit()
            return alias
        }
    }
    @Test fun preparationPausesAndRetriesUseSamePersistedRequestWithoutChangingEvidence() = runBlocking {
        val f = Fixture()
        try {
            val oldKey = f.oldPending("ATTESTATION_REJECTED")
            val before = f.identity.all.toMap()
            val evidence = Envelope("preserve", "old-device", "fingerprint", "signal", "sms", "opaque ciphertext", 1, state = "blocked", errorCode = "HTTP_403")
            f.db.outbox().insert(evidence)
            f.flow.prepare("A".repeat(48), f.expiry())
            assertEquals(0, f.bodies.size)
            val prepared = f.flow.view()!!
            assertEquals("prepared", prepared.state)
            assertTrue(prepared.fingerprint.matches(Regex("[0-9a-f]{64}")))
            assertFalse(f.journal.getString("journal", "")!!.contains("A".repeat(48)))
            assertNotEquals(Protocol.sha256(f.store.getCertificate(oldKey).publicKey.encoded), prepared.fingerprint)
            assertTrue(runCatching { f.flow.submit() }.exceptionOrNull() is PairingFailure)
            assertEquals("rejected", f.flow.view()!!.state)
            val restored = StagingPairing(f.journal, f.identity, f.repo.vault, f.transport, { f.time })
            val newId = UUID.randomUUID().toString()
            f.response = { ApiResult(201, JSONObject().put("device_id", newId)) }
            restored.submit()
            assertEquals(f.bodies[0], f.bodies[1])
            val sent = JSONObject(f.bodies[0])
            assertEquals(prepared.publicPem, sent.getString("public_key"))
            val certificate = java.security.cert.CertificateFactory.getInstance("X.509").generateCertificate(
                java.util.Base64.getDecoder().decode(sent.getJSONArray("attestation_certificate_chain").getString(0)).inputStream())
            assertEquals(prepared.fingerprint, Protocol.sha256(certificate.publicKey.encoded))
            assertEquals("accepted", restored.view()!!.state)
            assertEquals(before, f.identity.all)
            assertEquals(listOf(evidence), f.db.outbox().observe().first())
            assertTrue(f.store.containsAlias(oldKey))
            assertEquals("PENDING_EVIDENCE_MUST_BE_RESOLVED", runCatching { f.repo.activateStagedPairing() }.exceptionOrNull()!!.message)
            assertEquals(before, f.identity.all)
            assertEquals(listOf(evidence), f.db.outbox().observe().first())
        } finally { f.close() }
    }
    @Test fun unknownLegacyOutcomeCannotGenerateAnotherKey() = runBlocking {
        val f = Fixture()
        try {
            f.oldPending("PAIRING_CONNECTION_FAILED")
            val keys = Collections.list(f.store.aliases()).toSet()
            val before = f.identity.all.toMap()
            assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { f.flow.prepare("B".repeat(48), f.expiry()) }.exceptionOrNull()!!.message)
            assertEquals(keys, Collections.list(f.store.aliases()).toSet())
            assertEquals(before, f.identity.all)
            assertTrue(f.journal.all.isEmpty())
            assertTrue(f.bodies.isEmpty())
        } finally { f.close() }
    }
    @Test fun lostResponseBlocksRetryAndReplacementEvenAfterExpiry() = runBlocking {
        val f = Fixture()
        try {
            f.flow.prepare("C".repeat(48), f.expiry())
            val fingerprint = f.flow.view()!!.fingerprint
            f.response = { throw IOException("synthetic timeout") }
            runCatching { f.flow.submit() }
            assertEquals("uncertain", f.flow.view()!!.state)
            f.time = f.time.plusSeconds(600)
            assertEquals("uncertain", f.flow.view()!!.state)
            assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { f.flow.submit() }.exceptionOrNull()!!.message)
            assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { f.flow.prepare("D".repeat(48), f.expiry()) }.exceptionOrNull()!!.message)
            assertEquals(1, f.bodies.size)
            assertEquals(fingerprint, f.flow.view()!!.fingerprint)
        } finally { f.close() }
    }
    @Test fun expiredPreparedAttemptRetainsKeyAndNewQrNeedsDifferentFingerprint() = runBlocking {
        val f = Fixture()
        try {
            f.flow.prepare("E".repeat(48), f.expiry())
            val first = f.flow.view()!!.fingerprint
            val keys = Collections.list(f.store.aliases()).toSet()
            f.time = f.time.plusSeconds(300)
            assertEquals("expired", f.flow.view()!!.state)
            assertEquals("PAIRING_EXPIRED", runCatching { f.flow.submit() }.exceptionOrNull()!!.message)
            assertTrue(f.bodies.isEmpty())
            f.flow.prepare("F".repeat(48), f.expiry())
            assertNotEquals(first, f.flow.view()!!.fingerprint)
            assertTrue(Collections.list(f.store.aliases()).containsAll(keys))
            assertEquals("PAIRING_USE_EXISTING_ATTEMPT", runCatching { f.flow.prepare("F".repeat(48), f.expiry()) }.exceptionOrNull()!!.message)
        } finally { f.close() }
    }
    @Test fun acceptedPairingActivatesExplicitlyAndArchivesOldIdentity() = runBlocking {
        val f = Fixture()
        try {
            val oldKey = f.oldPending("ATTESTATION_REJECTED")
            val before = f.identity.all.toMap()
            f.flow.prepare("G".repeat(48), f.expiry())
            val id = UUID.randomUUID().toString()
            f.response = { ApiResult(201, JSONObject().put("device_id", id)) }
            f.flow.submit()
            assertEquals(before, f.identity.all)
            f.repo.activateStagedPairing()
            assertEquals(id, f.repo.device())
            assertEquals("activated", f.flow.view()!!.state)
            assertTrue(f.store.containsAlias(oldKey))
            val journal = JSONObject(f.repo.vault.decrypt(f.journal.getString("journal", null)!!, "staging-pairing-journal-v1"))
            assertEquals(oldKey, journal.getJSONArray("attempts").getJSONObject(0).getJSONObject("previous_capture_preferences").getString("alias"))
        } finally { f.close() }
    }
    @Test fun malformedSuccessAndServerErrorRemainUncertain() = runBlocking {
        for (code in listOf(201, 500)) {
            val f = Fixture()
            try {
                f.flow.prepare("H".repeat(48), f.expiry())
                f.response = { ApiResult(code, JSONObject().put("error", "ATTESTATION_REJECTED")) }
                runCatching { f.flow.submit() }
                assertEquals("uncertain", f.flow.view()!!.state)
                assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { f.flow.submit() }.exceptionOrNull()!!.message)
                assertEquals(1, f.bodies.size)
            } finally { f.close() }
        }
    }
}
