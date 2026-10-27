package `in`.hxdev.capture.core

import `in`.hxdev.capture.BuildConfig
import java.time.Instant
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.CertificatePinner
import okhttp3.ConnectionSpec
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.TlsVersion
import org.json.JSONObject

data class ApiResult(val code: Int, val json: JSONObject, val retryAfter: Long? = null)
interface DeviceTransport {
    val configured: Boolean
    suspend fun request(method: String, path: String, body: ByteArray = byteArrayOf(), device: String? = null, alias: String? = null): ApiResult
}
class DeviceApi(private val vault: KeyVault) : DeviceTransport {
    private val origin = Protocol.origin(BuildConfig.GATEWAY_ORIGIN)
    private val pins = BuildConfig.TLS_PINS.split(',').filter { it.isNotBlank() }
    override val configured = pins.distinct().size >= 2 && pins.all { it.matches(Regex("sha256/[A-Za-z0-9+/]{43}=")) } && !origin.host.endsWith(".invalid")
    private val client by lazy {
        check(configured) { "TLS_CONFIGURATION_REQUIRED" }
        OkHttpClient.Builder().followRedirects(false).followSslRedirects(false).retryOnConnectionFailure(false)
            .connectionSpecs(listOf(ConnectionSpec.Builder(ConnectionSpec.MODERN_TLS).tlsVersions(TlsVersion.TLS_1_3).build()))
            .certificatePinner(CertificatePinner.Builder().add(origin.host, *pins.toTypedArray()).build())
            .connectTimeout(10, TimeUnit.SECONDS).readTimeout(15, TimeUnit.SECONDS).callTimeout(20, TimeUnit.SECONDS).build()
    }
    override suspend fun request(method: String, path: String, body: ByteArray, device: String?, alias: String?): ApiResult = withContext(Dispatchers.IO) {
        require(path in setOf("/api/device/v1/pair", "/api/device/v1/heartbeat", "/api/device/v1/parser-rules", "/api/device/v1/signals", "/api/device/v1/faults"))
        val builder = Request.Builder().url("${BuildConfig.GATEWAY_ORIGIN}$path").header("Accept", "application/json")
        if (path != "/api/device/v1/pair") {
            check(!device.isNullOrBlank() && !alias.isNullOrBlank()) { "PAIRING_REQUIRED" }
            val time = Instant.now().epochSecond.toString()
            val nonce = Protocol.nonce()
            builder.header("X-Device-ID", device).header("X-Device-Timestamp", time).header("X-Device-Nonce", nonce)
                .header("X-Device-Body-SHA256", Protocol.sha256(body))
                .header("X-Device-Signature", vault.sign(alias, Protocol.canonical(method, path, time, nonce, body)))
        }
        builder.method(method, if (method == "GET") null else body.toRequestBody("application/json; charset=utf-8".toMediaType()))
        client.newCall(builder.build()).execute().use { response ->
            val type = response.header("Content-Type").orEmpty().substringBefore(';')
            if (response.code in 300..399 || type != "application/json") return@withContext ApiResult(422, JSONObject().put("error", "UNEXPECTED_ENDPOINT_RESPONSE"))
            val source = response.body?.source() ?: error("EMPTY_RESPONSE")
            source.request(262145)
            check(source.buffer.size <= 262144) { "RESPONSE_TOO_LARGE" }
            ApiResult(response.code, JSONObject(source.readUtf8()), DeliveryPolicy.retryAfter(response.header("Retry-After"), Instant.now()))
        }
    }
}
