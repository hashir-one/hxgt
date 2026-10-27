package `in`.hxdev.capture.core

import org.json.JSONObject

class PairingFailure(val code: String, val detail: String = "") : IllegalStateException(code)

object PairingFeedback {
    private val attestationCodes = setOf("PATCH_POLICY", "OS_POLICY", "BOOT_POLICY", "HARDWARE_POLICY", "KEY_POLICY", "APP_IDENTITY",
        "ATTESTATION_REQUIRED", "ATTESTATION_MALFORMED", "CHALLENGE_MISMATCH", "PUBLIC_KEY_MISMATCH", "CHAIN_INVALID",
        "ROOT_UNTRUSTED", "EXTENSION_POSITION", "EXTENSION_INVALID", "CERTIFICATE_TIME", "CERTIFICATE_REVOKED",
        "TRUST_UNAVAILABLE", "TRUST_STALE", "TRUST_DATA_INVALID", "ATTESTATION_NOT_CONFIGURED", "VERIFIER_UNAVAILABLE")
    fun safeDetail(json: JSONObject): String {
        if (json.optString("error") != "ATTESTATION_REJECTED") return ""
        val code = json.optString("code").takeIf { it in attestationCodes } ?: return ""
        if (code != "PATCH_POLICY") return code
        val details = json.optJSONObject("details") ?: return code
        val values = mutableListOf(code)
        details.optString("field").takeIf { it in setOf("os_patch", "vendor_patch", "boot_patch") }?.let { values += "field=$it" }
        details.optString("reason").takeIf { it in setOf("MISSING", "INVALID_FORMAT", "INVALID_DATE", "FUTURE", "BELOW_MINIMUM", "TOO_OLD") }?.let { values += "reason=$it" }
        for (key in listOf("actual", "minimum")) details.optString(key).takeIf { it.matches(Regex("[0-9]{6}([0-9]{2})?")) }?.let { values += "$key=$it" }
        details.optString("max_age_months").takeIf { it.matches(Regex("[0-9]{1,3}")) }?.let { values += "max_age_months=$it" }
        details.optString("server_date").takeIf { it.matches(Regex("[0-9]{4}-[0-9]{2}-[0-9]{2}")) }?.let { values += "server_date=$it" }
        return values.joinToString("\n")
    }
    private val serverCodes = setOf(
        "PAIRING_NOT_AVAILABLE", "DEVICE_SLOT_IN_USE", "DEVICE_KEY_ALREADY_REGISTERED",
        "MERCHANT_SUSPENDED", "MIGRATION_ATTESTATION_REQUIRED", "MIGRATION_DEVICE_REVOKED",
        "ATTESTATION_REQUIRED", "ATTESTATION_REJECTED", "ATTESTATION_POLICY_NOT_CONFIGURED",
        "ATTESTATION_CHALLENGE_MISMATCH", "ATTESTATION_APP_ID_MISMATCH", "ATTESTATION_SIGNER_MISMATCH",
        "ATTESTATION_UNAVAILABLE", "UNEXPECTED_ENDPOINT_RESPONSE"
    )
    fun responseCode(status: Int, json: JSONObject): String =
        listOf(json.optString("error"), json.optString("message"), json.optString("reason"))
            .firstOrNull { it in serverCodes } ?: "PAIR_HTTP_$status"

    fun message(code: String): String = when (code) {
        "PAIRING_OUTCOME_UNCERTAIN" -> "The previous submission may have reached the server. Keep this key and app data. The operator must resolve its outcome before retrying or preparing another QR."
        "PAIRING_EXPIRY_REQUIRED" -> "Enter the QR's exact expiry from the merchant panel in UTC, for example 2026-10-25T10:30:00Z. It must be within the next five minutes."
        "PAIRING_EXPIRED" -> "This QR has expired. Its key and evidence are retained. A fresh owner-approved QR needs a new key and a new server fingerprint."
        "PAIRING_USE_EXISTING_ATTEMPT" -> "This QR already has a saved key. Use its existing attempt; do not generate another key for the same QR."
        "PAIRING_PREPARATION_FAILED" -> "Key preparation did not complete. Nothing was submitted. Existing keys and evidence remain saved."
        "PAIRING_IDENTITY_CHANGED" -> "The capture identity changed after this attempt was prepared. Preserve app data and ask the operator to reconcile the saved pairing."
        "PAIRING_NOT_PREPARED", "PAIRING_NOT_ACCEPTED", "PENDING_KEY_UNAVAILABLE" -> "The required pending pairing state or key is unavailable. Preserve app data and contact the operator."
        "PAIRING_RECOVERY_REQUIRED" -> "A previous identity or attempt is saved. Keep its data. An uncertain outcome needs the pending-key server recovery API; checking the owner panel cannot resolve it."
        "PAIRING_NOT_AVAILABLE" -> "This token is unavailable and may already have been consumed. Keep its key and attempt. The server must resolve whether it registered before any replacement."
        "DEVICE_SLOT_IN_USE" -> "The slot is occupied. This does not prove whether an earlier submission registered. Keep the attempt and request authoritative server recovery."
        "DEVICE_KEY_ALREADY_REGISTERED" -> "This key may already be registered. Keep the attempt; authoritative pending-key recovery is required before changing identity."
        "PAIRING_CONNECTION_FAILED", "PAIRING_RESPONSE_INVALID" -> "The server may already have registered this key. Keep the saved attempt. A pending-key recovery API must resolve the outcome; the owner panel and token expiry are insufficient."
        "INVALID_PAIRING_QR" -> "This is not a valid pairing QR for the configured server. Scan a fresh QR from the merchant Devices page."
        "HARDWARE_ATTESTATION_UNAVAILABLE" -> "Android could not create a hardware-attested signing key. Check the phone's security setup, then prepare a new pairing before retrying."
        "CAPTURE_MUST_BE_STOPPED" -> "Stop capture before preparing a new pairing."
        "PENDING_EVIDENCE_MUST_BE_RESOLVED" -> "Unresolved payment evidence is still stored on this phone. Ask the operator to resolve it before re-pairing. Do not clear app data."
        "STORAGE_UNAVAILABLE" -> "The phone could not save its pairing state. Check available storage before continuing."
        "TLS_CONFIGURATION_REQUIRED" -> "Approved server TLS pins must be configured before pairing."
        "MERCHANT_SUSPENDED" -> "The merchant account is suspended. Contact the operator."
        "MIGRATION_DEVICE_REVOKED" -> "The old device is revoked, possibly by an earlier successful replacement. Keep this attempt until the server authoritatively resolves its outcome."
        else -> when {
            code in serverCodes && code.contains("ATTESTATION") -> "The server could not approve hardware attestation. Give the operator this code: $code. Fix the server policy before preparing a new pairing."
            code.matches(Regex("PAIR_HTTP_[0-9]{3}")) -> "The server returned HTTP ${code.removePrefix("PAIR_HTTP_")}. Preserve this attempt; an unknown result requires authoritative server recovery."
            else -> "The action could not complete. Check device health and recovery instructions."
        }
    }
}
