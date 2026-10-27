package `in`.hxdev.capture

import android.content.Context
import androidx.room.Room
import androidx.test.platform.app.InstrumentationRegistry
import `in`.hxdev.capture.core.*
import kotlinx.coroutines.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.util.UUID

class OutboxDeviceTest {
    private val context get() = InstrumentationRegistry.getInstrumentation().targetContext
    private class Transport(private val answer: ApiResult) : DeviceTransport {
        override val configured = true
        val bodies = mutableListOf<String>()
        override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult {
            bodies += String(body, Charsets.UTF_8)
            return answer
        }
    }
    @Test fun encryptedRecordSurvivesReopenAndCannotMoveToAnotherIdentity() = runBlocking<Unit> {
        val name = "queue-test-${UUID.randomUUID()}.db"
        val vault = KeyVault()
        val plain = "{\"amount_minor\":101,\"reference_number\":\"001234567890\"}"
        val encrypted = vault.encrypt(plain, "device:id")
        assertFalse(encrypted.contains("001234567890"))
        var db = Room.databaseBuilder(context, CaptureDatabase::class.java, name).build()
        val record = Envelope("id", "device", "fingerprint", "signal", "sms", encrypted, 1)
        assertTrue(db.outbox().insert(record) != -1L)
        assertEquals(-1L, db.outbox().insert(record.copy(id = "other-id")))
        db.close()
        db = Room.databaseBuilder(context, CaptureDatabase::class.java, name).build()
        try {
            val restored = db.outbox().next("device", 10)!!
            assertEquals(plain, vault.decrypt(restored.encryptedPayload, "device:id"))
            assertThrows(Exception::class.java) { vault.decrypt(restored.encryptedPayload, "different-device:id") }
            assertNull(db.outbox().next("different-device", 10))
        } finally { db.close(); context.deleteDatabase(name) }
    }
    @Test fun retryUsesImmutablePayloadAndAcknowledgesDuplicateServerCommit() = runBlocking<Unit> {
        val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
        val prefs = context.getSharedPreferences("capture-test", Context.MODE_PRIVATE)
        val device = UUID.randomUUID().toString()
        prefs.edit().clear().putString("device", device).putString("alias", "test-only").commit()
        val payload = "{\"client_signal_id\":\"stable-id\",\"amount_minor\":101}"
        val record = Envelope("stable-id", device, "dedup", "signal", "sms", KeyVault().encrypt(payload, "$device:stable-id"), 1)
        db.outbox().insert(record)
        val failing = Transport(ApiResult(503, JSONObject()))
        val first = CaptureRepository(context, db, failing, "capture-test")
        try {
            assertTrue(first.drain())
            val retried = db.outbox().next(device, Long.MAX_VALUE)!!
            assertEquals("stable-id", retried.id)
            assertEquals(record.encryptedPayload, retried.encryptedPayload)
            db.outbox().update(retried.copy(nextAttempt = 0))
            val accepted = Transport(ApiResult(202, JSONObject("""{"data":[{"accepted":true,"duplicate":true,"signal_id":4}]}""")))
            val second = CaptureRepository(context, db, accepted, "capture-test")
            try {
                assertFalse(second.drain())
                assertEquals(failing.bodies.single(), accepted.bodies.single())
                assertEquals(0, db.outbox().pending())
            } finally { second.scope.coroutineContext[Job]!!.cancelAndJoin() }
        } finally { first.scope.coroutineContext[Job]!!.cancelAndJoin(); db.close(); prefs.edit().clear().commit() }
    }
    @Test fun trustRefusalIsRetainedAndBlocksIdentity() = runBlocking<Unit> {
        val db = Room.inMemoryDatabaseBuilder(context, CaptureDatabase::class.java).build()
        val prefs = context.getSharedPreferences("capture-test", Context.MODE_PRIVATE)
        val device = UUID.randomUUID().toString()
        prefs.edit().clear().putString("device", device).commit()
        val record = Envelope("id", device, "fp", "signal", "sms", KeyVault().encrypt("{}", "$device:id"), 1)
        db.outbox().insert(record)
        val repo = CaptureRepository(context, db, Transport(ApiResult(403, JSONObject())), "capture-test")
        try {
            assertFalse(repo.drain())
            assertEquals(1, db.outbox().pending())
            assertEquals(1, db.outbox().blocked())
            assertTrue(prefs.getBoolean("identity_blocked", false))
            assertThrows(IllegalStateException::class.java) { runBlocking { repo.prepareFreshPairing() } }
        } finally { repo.scope.coroutineContext[Job]!!.cancelAndJoin(); db.close(); prefs.edit().clear().commit() }
    }
}
