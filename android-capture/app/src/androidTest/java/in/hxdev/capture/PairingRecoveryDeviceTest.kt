package `in`.hxdev.capture

import android.content.Context
import androidx.room.Room
import androidx.test.platform.app.InstrumentationRegistry
import `in`.hxdev.capture.core.*
import kotlinx.coroutines.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.io.IOException
import java.security.KeyStore
import java.util.UUID

class PairingRecoveryDeviceTest {
    @Test fun rejectedPairingShowsReasonAndFreshPairingPreservesOldKey() = runBlocking<Unit> {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val prefsName = "pair-recovery-test-${UUID.randomUUID()}"
        val prefs = context.getSharedPreferences(prefsName, Context.MODE_PRIVATE)
        val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
        val id = UUID.randomUUID().toString()
        var accept = false
        var pairRequests = 0
        val transport = object : DeviceTransport {
            override val configured = true
            override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult {
                if (path.endsWith("/pair")) {
                    pairRequests++
                    return if (accept) ApiResult(201, JSONObject().put("device_id", id).put("status", "offline").put("attestation_status", "verified"))
                        else ApiResult(422, JSONObject().put("error", "ATTESTATION_REJECTED"))
                }
                return ApiResult(200, JSONObject().put("version", JSONObject.NULL))
            }
        }
        val repo = CaptureRepository(context, db, transport, prefsName)
        val generatedAliases = mutableSetOf<String>()
        try {
            val failure = runCatching { repo.pair("A".repeat(48)) }.exceptionOrNull()
            assertEquals("ATTESTATION_REJECTED", (failure as PairingFailure).code)
            assertEquals("ATTESTATION_REJECTED", prefs.getString("error", null))
            val oldAlias = prefs.getString("alias", null)!!
            generatedAliases += oldAlias
            repo.refreshHealth()
            assertTrue(repo.health.value.pairingRecovery)
            assertNull(repo.device())
            assertEquals("PAIRING_RECOVERY_REQUIRED", (runCatching { repo.pair("B".repeat(48)) }.exceptionOrNull() as PairingFailure).code)
            assertEquals(1, pairRequests)

            repo.prepareFreshPairing()
            assertNull(prefs.getString("alias", null))
            assertNull(prefs.getString("pair_state", null))
            assertTrue(repo.vault.exists(oldAlias))
            accept = true
            repo.pair("C".repeat(48))
            assertEquals(id, repo.device())
            assertEquals("paired", prefs.getString("pair_state", null))
            assertNotEquals(oldAlias, prefs.getString("alias", null))
            assertFalse(repo.health.value.pairingRecovery)
            assertEquals(2, pairRequests)
        } finally {
            prefs.getString("alias", null)?.let { generatedAliases += it }
            repo.scope.coroutineContext[Job]!!.cancelAndJoin()
            db.close()
            prefs.edit().clear().commit()
            val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
            generatedAliases.forEach { store.deleteEntry(it) }
        }
    }

    @Test fun lostPairingResponseKeepsKeyAndRequiresExplicitRecovery() = runBlocking<Unit> {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val prefsName = "pair-timeout-test-${UUID.randomUUID()}"
        val prefs = context.getSharedPreferences(prefsName, Context.MODE_PRIVATE)
        val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
        val transport = object : DeviceTransport {
            override val configured = true
            override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult {
                throw IOException("synthetic connection interruption")
            }
        }
        val repo = CaptureRepository(context, db, transport, prefsName)
        try {
            assertEquals("PAIRING_CONNECTION_FAILED", (runCatching { repo.pair("D".repeat(48)) }.exceptionOrNull() as PairingFailure).code)
            assertNull(repo.device())
            assertTrue(repo.vault.exists(prefs.getString("alias", null)!!))
            repo.refreshHealth()
            assertTrue(repo.health.value.pairingRecovery)
            assertEquals("PAIRING_CONNECTION_FAILED", repo.health.value.error)
        } finally {
            val testAlias = prefs.getString("alias", null)
            repo.scope.coroutineContext[Job]!!.cancelAndJoin()
            db.close()
            prefs.edit().clear().commit()
            testAlias?.let { KeyStore.getInstance("AndroidKeyStore").apply { load(null); deleteEntry(it) } }
        }
    }
}
