package `in`.hxdev.capture

import `in`.hxdev.capture.core.PairingContract
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class PairingContractTest {
    private fun success() = JSONObject().put("device_id", "12345678-abcd-4321-8123-123456789abc")
        .put("status", "offline").put("attestation_status", "verified")
    @Test fun completeCanonicalSuccessOnly() {
        assertNotNull(PairingContract.verifiedId(success()))
        for (field in listOf("device_id", "status", "attestation_status")) {
            assertNull(PairingContract.verifiedId(success().apply { remove(field) }))
            assertNull(PairingContract.verifiedId(success().put(field, "unexpected")))
        }
        for (id in listOf("1-1-1-1-1", "12345678-ABCD-4321-8123-123456789ABC", "", "null"))
            assertNull(PairingContract.verifiedId(success().put("device_id", id)))
    }
    @Test fun consumedAndUnavailableTokensNeverProveRejection() {
        for (code in listOf("PAIRING_NOT_AVAILABLE", "DEVICE_SLOT_IN_USE", "MIGRATION_DEVICE_REVOKED", "DEVICE_KEY_ALREADY_REGISTERED"))
            assertFalse(PairingContract.definitiveRejection(422, JSONObject().put("error", code)))
        assertTrue(PairingContract.definitiveRejection(422, JSONObject().put("error", "ATTESTATION_REJECTED")))
        assertFalse(PairingContract.definitiveRejection(503, JSONObject().put("error", "ATTESTATION_REJECTED")))
    }
    @Test fun receiptsCannotBeMovedBetweenAttemptsTokensOrKeys() {
        val attempt = JSONObject().put("attempt_id", "attempt").put("alias", "key")
            .put("token_hash", "a".repeat(64)).put("fingerprint", "b".repeat(64))
        val receipt = PairingContract.receipt(attempt, "rejected")
        assertTrue(PairingContract.bound(attempt, receipt, "rejected"))
        assertFalse(PairingContract.bound(attempt, receipt, "accepted"))
        for (field in listOf("attempt_id", "alias", "token_hash", "fingerprint"))
            assertFalse(PairingContract.bound(attempt, JSONObject(receipt.toString()).put(field, "other"), "rejected"))
        assertFalse(PairingContract.bound(attempt, JSONObject(receipt.toString()).put("version", 1), "rejected"))
    }
}
