package `in`.hxdev.capture.core

import android.content.SharedPreferences
import org.json.JSONObject
import java.util.UUID

object PairingContract {
    fun verifiedId(json: JSONObject): String? {
        val id = json.optString("device_id")
        if (!id.matches(Regex("[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}"))) return null
        if (runCatching { UUID.fromString(id).toString() }.getOrNull() != id) return null
        return id.takeIf { json.optString("status") == "offline" && json.optString("attestation_status") == "verified" }
    }

    // Only an explicitly rejected attestation response for this known request is retryable.
    // Unavailable tokens/occupied slots/revoked migrations can hide an earlier registration.
    fun definitiveRejection(http: Int, json: JSONObject) = http == 422 && json.optString("error") == "ATTESTATION_REJECTED"

    fun receipt(attempt: JSONObject, state: String, response: JSONObject = JSONObject()): JSONObject = JSONObject()
        .put("version", 2).put("attempt_id", attempt.getString("attempt_id"))
        .put("alias", attempt.getString("alias")).put("token_hash", attempt.getString("token_hash"))
        .put("fingerprint", attempt.getString("fingerprint")).put("state", state)
        .put("device_id", verifiedId(response) ?: "")
        .put("status", if (verifiedId(response) != null) "offline" else "")
        .put("attestation_status", if (verifiedId(response) != null) "verified" else "")

    fun bound(attempt: JSONObject, receipt: JSONObject?, state: String): Boolean = receipt != null &&
        receipt.optInt("version") == 2 && receipt.optString("state") == state &&
        listOf("attempt_id", "alias", "token_hash", "fingerprint").all {
            attempt.optString(it).isNotEmpty() && receipt.optString(it) == attempt.optString(it)
        }

    fun legacyResolved(identity: SharedPreferences, vault: KeyVault): Boolean {
        val alias = identity.getString("alias", null) ?: return identity.getString("pair_state", null) == null
        val encoded = identity.getString("pair_receipt", null) ?: return false
        return runCatching {
            val attempt = JSONObject(vault.decrypt(encoded, "legacy-pair-receipt-v2"))
            val receipt = attempt.optJSONObject("receipt")
            val fingerprint = Protocol.sha256(java.security.KeyStore.getInstance("AndroidKeyStore").apply { load(null) }.getCertificate(alias).publicKey.encoded)
            attempt.getString("alias") == alias && attempt.getString("fingerprint") == fingerprint &&
                (bound(attempt, receipt, "rejected") || bound(attempt, receipt, "not_submitted"))
        }.getOrDefault(false)
    }
}
