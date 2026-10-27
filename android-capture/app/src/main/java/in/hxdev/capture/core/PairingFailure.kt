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
        "PAIRING_RECOVERY_REQUIRED" -> "A previous pairing is still saved on this phone. If the owner has revoked it, choose Prepare a new pairing, verify your phone lock, then scan a fresh QR."
        "PAIRING_NOT_AVAILABLE" -> "The pairing QR is expired, already used, or not approved. Check the merchant Devices page, prepare a new pairing on this phone, and generate a fresh QR."
        "DEVICE_SLOT_IN_USE" -> "That server slot is still occupied. The owner must revoke the old device or approve its replacement, then prepare a new pairing on this phone and generate a fresh QR."
        "DEVICE_KEY_ALREADY_REGISTERED" -> "This key is already registered. Check the merchant Devices page before preparing a fresh pairing; a previous request may have succeeded."
        "PAIRING_CONNECTION_FAILED", "PAIRING_RESPONSE_INVALID" -> "The pairing result could not be confirmed. Check the merchant Devices page first: the server may already have added this phone. Then use Prepare a new pairing if the owner has revoked or approved replacement of that entry."
        "INVALID_PAIRING_QR" -> "This is not a valid pairing QR for the configured server. Scan a fresh QR from the merchant Devices page."
        "HARDWARE_ATTESTATION_UNAVAILABLE" -> "Android could not create a hardware-attested signing key. Check the phone's security setup, then prepare a new pairing before retrying."
        "CAPTURE_MUST_BE_STOPPED" -> "Stop capture before preparing a new pairing."
        "PENDING_EVIDENCE_MUST_BE_RESOLVED" -> "Unresolved payment evidence is still stored on this phone. Ask the operator to resolve it before re-pairing. Do not clear app data."
        "STORAGE_UNAVAILABLE" -> "The phone could not save its pairing state. Check available storage before continuing."
        "TLS_CONFIGURATION_REQUIRED" -> "Approved server TLS pins must be configured before pairing."
        "MERCHANT_SUSPENDED" -> "The merchant account is suspended. Contact the operator."
        "MIGRATION_DEVICE_REVOKED" -> "The replacement request refers to an already revoked device. Generate a fresh owner-approved QR."
        else -> when {
            code in serverCodes && code.contains("ATTESTATION") -> "The server could not approve hardware attestation. Give the operator this code: $code. Fix the server policy before preparing a new pairing."
            code.matches(Regex("PAIR_HTTP_[0-9]{3}")) -> "The server refused pairing (${code.removePrefix("PAIR_HTTP_")}). Ask the operator to check the server response, then prepare a new pairing before scanning a fresh QR."
            else -> "The action could not complete. Check device health and recovery instructions."
        }
    }
}
