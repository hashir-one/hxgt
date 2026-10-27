package `in`.hxdev.capture

import androidx.test.platform.app.InstrumentationRegistry
import okhttp3.CertificatePinner
import okhttp3.ConnectionSpec
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.TlsVersion
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assume.assumeTrue
import org.junit.Test
import java.net.URI
import java.util.concurrent.TimeUnit

class TlsDeviceTest {
    @Test fun operatorServerAcceptsPinnedTls13OnPhysicalPhone() {
        // Explicit opt-in: a read-only unauthenticated request, never a pairing/payment mutation.
        assumeTrue(InstrumentationRegistry.getArguments().getString("verifyLiveTls") == "true")
        val host = URI(BuildConfig.GATEWAY_ORIGIN).host
        val pins = BuildConfig.TLS_PINS.split(',').filter { it.isNotBlank() }
        val client = OkHttpClient.Builder()
            .followRedirects(false).followSslRedirects(false)
            .connectionSpecs(listOf(ConnectionSpec.Builder(ConnectionSpec.MODERN_TLS)
                .tlsVersions(TlsVersion.TLS_1_3).build()))
            .certificatePinner(CertificatePinner.Builder().add(host, *pins.toTypedArray()).build())
            .callTimeout(20, TimeUnit.SECONDS).build()
        try {
            client.newCall(Request.Builder()
                .url("${BuildConfig.GATEWAY_ORIGIN}/api/device/v1/parser-rules")
                .header("Accept", "application/json").get().build()).execute().use { response ->
                assertNotNull(response.handshake)
                assertEquals(TlsVersion.TLS_1_3, response.handshake!!.tlsVersion)
                assertEquals(host, response.request.url.host)
                // Authorization response is irrelevant to this TLS-only check.
            }
        } finally {
            client.connectionPool.evictAll()
            client.dispatcher.executorService.shutdown()
        }
    }
}
