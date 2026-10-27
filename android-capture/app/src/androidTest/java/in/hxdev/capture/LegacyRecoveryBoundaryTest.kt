package `in`.hxdev.capture

import android.content.Context
import androidx.room.Room
import androidx.test.platform.app.InstrumentationRegistry
import `in`.hxdev.capture.core.*
import kotlinx.coroutines.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.security.KeyStore
import java.util.UUID

/** Run this class in both staging-enabled and staging-disabled builds. */
class LegacyRecoveryBoundaryTest {
    @Test fun mutableErrorsCannotAuthorizeLegacyResetInEitherBuild() = runBlocking {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        for (error in listOf("ATTESTATION_REJECTED", "PAIRING_NOT_AVAILABLE", "DEVICE_SLOT_IN_USE", "MIGRATION_DEVICE_REVOKED", "PAIRING_CONNECTION_FAILED")) {
            val name = "legacy-boundary-${UUID.randomUUID()}"
            val prefs = context.getSharedPreferences(name, Context.MODE_PRIVATE)
            val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
            val api = object : DeviceTransport {
                override val configured = true
                override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult = error("Network must not be called")
            }
            prefs.edit().putString("alias", "retained-legacy-association").putString("pair_state", "sending").putString("error", error).commit()
            val before = prefs.all.toMap()
            val repo = CaptureRepository(context, db, api, name)
            try {
                assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { repo.prepareFreshPairing() }.exceptionOrNull()!!.message)
                assertEquals(before, prefs.all)
            } finally { repo.scope.coroutineContext[Job]!!.cancelAndJoin(); db.close(); prefs.edit().clear().commit() }
        }
    }
    @Test fun incompleteSuccessCannotActivateOrResetLegacyKey() = runBlocking {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val id = UUID.randomUUID().toString()
        for (json in listOf(JSONObject().put("device_id", id), JSONObject().put("device_id", id).put("status", "offline").put("attestation_status", "submitted"))) {
            val name = "legacy-success-${UUID.randomUUID()}"
            val prefs = context.getSharedPreferences(name, Context.MODE_PRIVATE)
            val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
            var calls = 0
            val api = object : DeviceTransport {
                override val configured = true
                override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult { calls++; return ApiResult(201, json) }
            }
            val repo = CaptureRepository(context, db, api, name)
            try {
                assertEquals("PAIRING_RESPONSE_INVALID", runCatching { repo.pair("P".repeat(48)) }.exceptionOrNull()!!.message)
                assertNull(repo.device())
                val alias = prefs.getString("alias", null)!!
                assertTrue(repo.vault.exists(alias))
                prefs.edit().putString("error", "ATTESTATION_REJECTED").commit()
                assertEquals("PAIRING_OUTCOME_UNCERTAIN", runCatching { repo.prepareFreshPairing() }.exceptionOrNull()!!.message)
                assertEquals(alias, prefs.getString("alias", null))
                assertEquals(1, calls)
            } finally {
                val alias = prefs.getString("alias", null)
                repo.scope.coroutineContext[Job]!!.cancelAndJoin(); db.close(); prefs.edit().clear().commit()
                alias?.let { KeyStore.getInstance("AndroidKeyStore").apply { load(null); deleteEntry(it) } }
            }
        }
    }
}
