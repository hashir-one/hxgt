package `in`.hxdev.capture.core

import java.net.URI
import java.security.MessageDigest
import java.security.SecureRandom
import java.time.Instant
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import org.json.JSONObject

object Protocol {
    fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it.toInt() and 255) }
    fun canonical(method: String, path: String, timestamp: String, nonce: String, body: ByteArray): ByteArray {
        require(path.startsWith("/api/device/v1/") && !path.contains('?') && !path.contains('#') && !path.contains('\n'))
        require(timestamp.matches(Regex("[0-9]+")) && nonce.matches(Regex("[a-f0-9]{64}")))
        return listOf(method.uppercase(Locale.ROOT), path, timestamp, nonce, sha256(body)).joinToString("\n").toByteArray(Charsets.UTF_8)
    }
    fun nonce() = ByteArray(32).also { SecureRandom().nextBytes(it) }.joinToString("") { "%02x".format(it.toInt() and 255) }
    fun challenge(token: String): ByteArray = MessageDigest.getInstance("SHA-256").digest("hx-pair-v1\n$token".toByteArray(Charsets.UTF_8))
    fun origin(value: String): URI = URI(value).also {
        require(it.scheme == "https" && !it.host.isNullOrBlank() && it.userInfo == null && it.port == -1 && it.rawQuery == null && it.rawFragment == null && it.rawPath.isNullOrEmpty()) { "INVALID_ORIGIN" }
    }
    fun pairingToken(input: String, origin: String): String {
        val approved = origin(origin)
        val token = if (input.trim().startsWith("{")) {
            val data = JSONObject(input)
            require(data.keys().asSequence().toSet() == setOf("url", "token")) { "INVALID_QR" }
            val uri = URI(data.getString("url"))
            require(uri.scheme == "https" && uri.host == approved.host && uri.port == -1 && uri.userInfo == null && uri.rawQuery == null && uri.rawFragment == null && uri.rawPath == "/api/device/v1/pair") { "WRONG_PAIRING_ORIGIN" }
            data.getString("token")
        } else input.trim()
        require(token.matches(Regex("[A-Za-z0-9]{48}"))) { "INVALID_PAIRING_TOKEN" }
        return token
    }
}

enum class DeliveryAction { ACKNOWLEDGE, RETRY, BLOCK }
object DeliveryPolicy {
    fun action(code: Int, accepted: Boolean): DeliveryAction = when {
        code in 200..299 && accepted -> DeliveryAction.ACKNOWLEDGE
        code == 0 || code == 408 || code == 429 || code >= 500 -> DeliveryAction.RETRY
        else -> DeliveryAction.BLOCK
    }
    fun delayMillis(attempt: Int, jitter: Double): Long = ((5_000L shl attempt.coerceIn(0, 6)).coerceAtMost(300_000) * (0.75 + jitter.coerceIn(0.0, 1.0) * 0.25)).toLong()
    fun retryAfter(value: String?, now: Instant): Long? = value?.let {
        it.toLongOrNull()?.takeIf { seconds -> seconds >= 0 && seconds <= 604800 }?.times(1000)
            ?: runCatching { (ZonedDateTime.parse(it, DateTimeFormatter.RFC_1123_DATE_TIME).toInstant().toEpochMilli() - now.toEpochMilli()).coerceIn(0, 604800000) }.getOrNull()
    }
}
