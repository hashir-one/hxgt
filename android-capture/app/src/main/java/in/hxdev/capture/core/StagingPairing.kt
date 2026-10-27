package `in`.hxdev.capture.core

import android.content.SharedPreferences
import `in`.hxdev.capture.BuildConfig
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import org.json.JSONArray
import org.json.JSONObject
import java.security.KeyStore
import java.time.Instant
import java.util.UUID

data class StagingPairingView(
    val state: String, val fingerprint: String = "", val publicPem: String = "",
    val expiresAt: String = "", val error: String = "", val device: String = "", val detail: String = ""
)

/** Separate encrypted attempt journal. Never mutates capture preferences, keys, or outbox. */
class StagingPairing(
    private val prefs: SharedPreferences,
    private val identity: SharedPreferences,
    private val vault: KeyVault,
    private val api: DeviceTransport,
    private val now: () -> Instant = { Instant.now() }
) {
    private val mutex = Mutex()
    private val definitiveRejections = setOf(
        "ATTESTATION_REJECTED", "ATTESTATION_REQUIRED", "ATTESTATION_POLICY_NOT_CONFIGURED",
        "ATTESTATION_CHALLENGE_MISMATCH", "ATTESTATION_APP_ID_MISMATCH", "ATTESTATION_SIGNER_MISMATCH",
        "ATTESTATION_UNAVAILABLE", "PAIRING_NOT_AVAILABLE", "DEVICE_SLOT_IN_USE",
        "MERCHANT_SUSPENDED", "MIGRATION_ATTESTATION_REQUIRED", "MIGRATION_DEVICE_REVOKED"
    )
    private fun gate() { check(BuildConfig.DEBUG && BuildConfig.STAGING_PAIRING) { "STAGING_ONLY" } }
    private fun journal(): JSONObject = prefs.getString("journal", null)?.let {
        JSONObject(vault.decrypt(it, "staging-pairing-journal-v1"))
    } ?: JSONObject().put("attempts", JSONArray())
    private fun save(journal: JSONObject) {
        check(prefs.edit().putString("journal", vault.encrypt(journal.toString(), "staging-pairing-journal-v1")).commit()) { "STORAGE_UNAVAILABLE" }
    }
    private fun latest(journal: JSONObject): JSONObject? = journal.getJSONArray("attempts").let {
        if (it.length() == 0) null else it.getJSONObject(it.length() - 1)
    }
    private fun binding(): String = Protocol.sha256(listOf("device", "alias", "pair_state")
        .joinToString("\n") { identity.getString(it, "").orEmpty() }.toByteArray())
    private fun publicMaterial(alias: String): Pair<String, String> {
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val chain = checkNotNull(store.getCertificateChain(alias)) { "PENDING_KEY_UNAVAILABLE" }
        check(chain.isNotEmpty())
        return Protocol.sha256(chain.first().publicKey.encoded) to vault.publicPem(alias)
    }
    private fun requireLegacyResolved(journal: JSONObject) {
        val currentBinding = binding()
        val savedBinding = journal.optString("identity_binding")
        if (savedBinding.isNotEmpty()) {
            check(savedBinding == currentBinding) { "PAIRING_IDENTITY_CHANGED" }
            return
        }
        val alias = identity.getString("alias", null)
        val device = identity.getString("device", null)
        if (alias != null && device == null) {
            // A legacy sending state is uncertain unless a specific rejection was actually saved.
            val reason = identity.getString("error", "").orEmpty()
            check(identity.getString("pair_state", "") == "sending" && reason in definitiveRejections) {
                "PAIRING_OUTCOME_UNCERTAIN"
            }
            journal.put("legacy_resolution", JSONObject().put("alias", alias)
                .put("fingerprint", publicMaterial(alias).first).put("outcome", "rejected")
                .put("reason", reason).put("recorded_at", now().toString()))
        }
        journal.put("identity_binding", currentBinding)
        // Public identity history is preserved, including the prior key alias.
        journal.put("previous_identity", JSONObject().put("alias", alias ?: JSONObject.NULL)
            .put("device", device ?: JSONObject.NULL).put("pair_state", identity.getString("pair_state", "")))
    }
    fun view(): StagingPairingView? {
        gate()
        val attempt = latest(journal()) ?: return null
        val storedState = attempt.getString("state")
        val state = when {
            storedState == "submitting" -> "uncertain"
            storedState in setOf("prepared", "rejected") && !now().isBefore(Instant.parse(attempt.getString("expires_at"))) -> "expired"
            else -> storedState
        }
        return StagingPairingView(state, attempt.optString("fingerprint"), attempt.optString("public_key"),
            attempt.getString("expires_at"), attempt.optString("error"), attempt.optString("device_id"), attempt.optString("detail"))
    }
    suspend fun prepare(input: String, expiryInput: String) = mutex.withLock {
        gate()
        val journal = journal()
        requireLegacyResolved(journal)
        latest(journal)?.let {
            check(it.getString("state") !in setOf("submitting", "uncertain", "accepted")) { "PAIRING_OUTCOME_UNCERTAIN" }
        }
        val token = try { Protocol.pairingToken(input, BuildConfig.GATEWAY_ORIGIN) }
            catch (_: Exception) { throw PairingFailure("INVALID_PAIRING_QR") }
        val expiry = try { Instant.parse(expiryInput.trim()) }
            catch (_: Exception) { throw PairingFailure("PAIRING_EXPIRY_REQUIRED") }
        check(now().isBefore(expiry)) { "PAIRING_EXPIRED" }
        // Server remains authoritative. A transcription error must not create a long-lived local token.
        check(!expiry.isAfter(now().plusSeconds(300))) { "PAIRING_EXPIRY_REQUIRED" }
        val tokenHash = Protocol.sha256(token.toByteArray())
        val attempts = journal.getJSONArray("attempts")
        check((0 until attempts.length()).none { attempts.getJSONObject(it).optString("token_hash") == tokenHash }) { "PAIRING_USE_EXISTING_ATTEMPT" }
        val alias = "hx-staging-${UUID.randomUUID()}"
        val attempt = JSONObject().put("alias", alias).put("state", "creating")
            .put("expires_at", expiry.toString()).put("token_hash", tokenHash).put("created_at", now().toString())
        attempts.put(attempt)
        save(journal) // Persist alias before key generation; never overwrite an earlier attempt.
        try {
            vault.generate(alias, token)
            val material = publicMaterial(alias)
            val body = JSONObject().put("pairing_token", token).put("public_key", material.second)
                .put("attestation_certificate_chain", JSONArray(vault.chain(alias)))
                .put("app_version", BuildConfig.VERSION_NAME)
                .put("metadata", JSONObject().put("label", "Dedicated capture phone")
                    .put("model", android.os.Build.MODEL.take(80)).put("android_version", android.os.Build.VERSION.RELEASE.take(20)))
            attempt.put("fingerprint", material.first).put("public_key", material.second)
                .put("request", body.toString()).put("state", "prepared")
            save(journal)
        } catch (failure: Exception) {
            // Retain alias and any generated key. Nothing has been sent.
            attempt.put("state", "preparation_failed").put("error", "PAIRING_PREPARATION_FAILED")
            save(journal)
            throw PairingFailure("PAIRING_PREPARATION_FAILED")
        }
    }
    suspend fun submit() = mutex.withLock {
        gate()
        check(api.configured) { "TLS_CONFIGURATION_REQUIRED" }
        val journal = journal()
        requireLegacyResolved(journal)
        val attempt = checkNotNull(latest(journal)) { "PAIRING_NOT_PREPARED" }
        check(attempt.getString("state") in setOf("prepared", "rejected")) { "PAIRING_OUTCOME_UNCERTAIN" }
        if (!now().isBefore(Instant.parse(attempt.getString("expires_at")))) {
            attempt.put("state", "expired"); save(journal)
            throw PairingFailure("PAIRING_EXPIRED")
        }
        check(publicMaterial(attempt.getString("alias")).first == attempt.getString("fingerprint")) { "PENDING_KEY_UNAVAILABLE" }
        val body = attempt.getString("request")
        attempt.put("state", "submitting"); save(journal)
        // A process death/cancellation from here leaves an uncertain submission. Never blindly retry.
        val result = try { api.request("POST", "/api/device/v1/pair", body.toByteArray(Charsets.UTF_8)) }
        catch (cancelled: CancellationException) { throw cancelled }
        catch (_: Exception) {
            attempt.put("state", "uncertain").put("error", "PAIRING_OUTCOME_UNCERTAIN"); save(journal)
            throw PairingFailure("PAIRING_OUTCOME_UNCERTAIN")
        }
        if (result.code == 201) {
            val id = runCatching { UUID.fromString(result.json.getString("device_id")).toString() }.getOrNull()
            if (id != null) {
                attempt.put("state", "accepted").put("device_id", id).put("error", ""); save(journal)
                return@withLock
            }
        }
        val code = PairingFeedback.responseCode(result.code, result.json)
        val rejected = result.code in 400..499 && code in definitiveRejections
        attempt.put("state", if (rejected) "rejected" else "uncertain")
            .put("error", if (rejected) code else "PAIRING_OUTCOME_UNCERTAIN")
            .put("detail", PairingFeedback.safeDetail(result.json))
        save(journal)
        throw PairingFailure(attempt.getString("error"), attempt.optString("detail"))
    }
    // Used only when capture is stopped and the outbox is empty. Preserve original identity in journal.
    suspend fun activate(install: (String, String) -> Unit) = mutex.withLock {
        gate()
        val journal = journal()
        val attempt = checkNotNull(latest(journal))
        check(attempt.getString("state") == "accepted") { "PAIRING_NOT_ACCEPTED" }
        check(publicMaterial(attempt.getString("alias")).first == attempt.getString("fingerprint"))
        // Recover a crash after the atomic identity commit but before journaling activation.
        if (identity.getString("device", null) == attempt.getString("device_id") &&
            identity.getString("alias", null) == attempt.getString("alias")) {
            attempt.put("state", "activated"); journal.put("identity_binding", binding()); save(journal)
            return@withLock
        }
        requireLegacyResolved(journal)
        attempt.put("previous_capture_preferences", JSONObject(identity.all))
        save(journal)
        install(attempt.getString("device_id"), attempt.getString("alias"))
        // The accepted record remains the durable recovery receipt even if this save fails.
        attempt.put("state", "activated")
        journal.put("identity_binding", binding())
        save(journal)
    }
}
