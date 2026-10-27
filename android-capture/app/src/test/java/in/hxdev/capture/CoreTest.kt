package `in`.hxdev.capture

import `in`.hxdev.capture.core.*
import `in`.hxdev.capture.parser.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.security.KeyFactory
import java.security.Signature
import java.security.spec.X509EncodedKeySpec
import java.time.Instant
import java.util.Base64

class CoreTest {
    private fun ruleJson() = JSONObject("""{
        "version":1,"source":"kotak","rules":{
            "schema":"hx-credit-re2-v1","sender_ids":["XX-KOTAKB"],
            "notification_packages":["test.bank.fixture"],
            "notification_signing_sha256":{"test.bank.fixture":["${"ab".repeat(32)}"]},
            "amount_pattern":"(?i)(?:INR|Rs[.]?|₹)\\s*([0-9,]+(?:\\.[0-9]{1,2})?)\\s+credited",
            "reference_pattern":"(?i)ref\\s*([0-9]{12})\\b",
            "credit_pattern":"(?i)\\bcredited\\b","reject_pattern":"(?i)\\b(promotion|offer|balance only)\\b"
        }}""")
    private fun parser() = CreditParser(Rules.decode(ruleJson()))
    @Test fun parsesCreditWithLeadingZeroReferenceAndPaise() {
        assertEquals(Credit(101, "001234567890"), parser().parse("sms", "XX-KOTAKB", "INR 1.01 credited\nRef 001234567890"))
        assertEquals(Credit(100, "001234567890"), parser().parse("notification", "test.bank.fixture", "₹1 credited\nRef 001234567890"))
    }
    @Test fun handlesIndianAndWesternGroupingWithoutFloatingPoint() {
        assertEquals(12345678L, CreditParser.amount("1,23,456.78"))
        assertEquals(12345678L, CreditParser.amount("123,456.78"))
        assertEquals(110L, CreditParser.amount("1.1"))
        listOf("1,2.00", "0", "-1", "1.001", "1e3", "NaN", "9223372036854775807").forEach { assertNull(it, CreditParser.amount(it)) }
    }
    @Test fun rejectsNonCreditsEvenIfCreditedWordIsPresent() {
        listOf("OTP 123456", "debited", "transaction failed", "promotion", "will be credited", "credit card", "reversal").forEach {
            assertNull(it, parser().parse("sms", "XX-KOTAKB", "INR 1 credited Ref 001234567890 $it"))
        }
    }
    @Test fun rejectsSpoofedSourcesAndAmbiguousEvidence() {
        val text = "INR 1 credited Ref 001234567890"
        assertNull(parser().parse("sms", "YY-KOTAKB", text))
        assertNull(parser().parse("notification", "com.messaging", text))
        assertNull(parser().parse("email", "XX-KOTAKB", text))
        assertNull(parser().parse("sms", "XX-KOTAKB", "$text INR 2 credited"))
        assertNull(parser().parse("sms", "XX-KOTAKB", "$text Ref 009876543210"))
        assertEquals(Credit(100, null), parser().parse("sms", "XX-KOTAKB", "INR 1 credited"))
    }
    @Test fun duplicateFieldsDoNotDuplicateOneCredit() {
        val text = "INR 1 credited Ref 001234567890"
        assertEquals(Credit(100, "001234567890"), parser().parse("sms", "XX-KOTAKB", "$text\n$text"))
    }
    @Test fun rejectsMalformedRulesAndMissingBankCertificateIdentity() {
        val missing = ruleJson(); missing.getJSONObject("rules").remove("notification_signing_sha256")
        assertThrows(Exception::class.java) { Rules.decode(missing) }
        val pcre = ruleJson(); pcre.getJSONObject("rules").remove("schema")
        assertThrows(Exception::class.java) { Rules.decode(pcre) }
        val bad = ruleJson(); bad.getJSONObject("rules").put("amount_pattern", "(?<=INR)([0-9]+)")
        assertThrows(Exception::class.java) { Rules.decode(bad) }
        assertThrows(Exception::class.java) { Rules.decode(JSONObject("""{"version":null,"source":"kotak","rules":[]}""")) }
    }
    @Test(timeout = 1000) fun inputIsBoundedAndRegexIsLinearTime() {
        assertNull(parser().parse("sms", "XX-KOTAKB", "a".repeat(100000)))
        val json = ruleJson(); json.getJSONObject("rules").put("credit_pattern", "(a+)+b")
        assertNull(CreditParser(Rules.decode(json)).parse("sms", "XX-KOTAKB", "a".repeat(8000)))
    }
    @Test fun pairingRejectsEveryUnapprovedDestination() {
        val token = "a".repeat(48)
        val origin = "https://pay.hxdev.in"
        assertEquals(token, Protocol.pairingToken(token, origin))
        assertEquals(token, Protocol.pairingToken(JSONObject().put("url", "$origin/api/device/v1/pair").put("token", token).toString(), origin))
        listOf("http://pay.hxdev.in/api/device/v1/pair", "https://evil.example/api/device/v1/pair", "$origin:443/api/device/v1/pair", "$origin/api/device/v1/pair?x=1", "$origin/api/device/v1/pair#x", "https://evil@pay.hxdev.in/api/device/v1/pair", "$origin/api/device/v1/%70air", "$origin/api/device/v1/pair/").forEach { url ->
            assertThrows(url, Exception::class.java) { Protocol.pairingToken(JSONObject().put("url", url).put("token", token).toString(), origin) }
        }
    }
    @Test fun canonicalBytesAreExactAndEmptyGetHashIsCorrect() {
        val nonce = "ab".repeat(32)
        val actual = Protocol.canonical("get", "/api/device/v1/parser-rules", "1792922400", nonce, byteArrayOf())
        assertEquals("GET\n/api/device/v1/parser-rules\n1792922400\n$nonce\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", String(actual))
        assertFalse(String(actual).endsWith("\n"))
        assertThrows(Exception::class.java) { Protocol.canonical("GET", "/api/device/v1/parser-rules?q=1", "1", nonce, byteArrayOf()) }
    }
    @Test fun retriesOnlyTransportFailuresAndRequiresPerRecordAcceptance() {
        assertEquals(DeliveryAction.ACKNOWLEDGE, DeliveryPolicy.action(202, true))
        assertEquals(DeliveryAction.BLOCK, DeliveryPolicy.action(202, false))
        listOf(401, 403, 409, 422, 302).forEach { assertEquals(DeliveryAction.BLOCK, DeliveryPolicy.action(it, false)) }
        listOf(0, 408, 429, 500, 503).forEach { assertEquals(DeliveryAction.RETRY, DeliveryPolicy.action(it, false)) }
        assertEquals(300000L, DeliveryPolicy.delayMillis(100, 1.0))
        assertEquals(120000L, DeliveryPolicy.retryAfter("120", Instant.EPOCH))
        assertEquals(60000L, DeliveryPolicy.retryAfter("Thu, 1 Jan 1970 00:01:00 GMT", Instant.EPOCH))
        assertNull(DeliveryPolicy.retryAfter("bad", Instant.EPOCH))
    }
    @Test fun sharedJvmEcdsaFixtureVerifiesAndTamperingFails() {
        val fixture = JSONObject(java.io.File("../protocol-fixtures/signing.json").readText())
        val body = fixture.getString("body").toByteArray(Charsets.UTF_8)
        assertEquals(fixture.getString("body_sha256"), Protocol.sha256(body))
        val canonical = Protocol.canonical("POST", fixture.getString("path"), fixture.getString("timestamp"), fixture.getString("nonce"), body)
        assertEquals(fixture.getString("canonical"), String(canonical, Charsets.UTF_8))
        val publicKey = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(Base64.getDecoder().decode(fixture.getString("public_key_der"))))
        fun verify(bytes: ByteArray): Boolean = Signature.getInstance("SHA256withECDSA").run { initVerify(publicKey); update(bytes); this.verify(Base64.getDecoder().decode(fixture.getString("signature"))) }
        assertTrue(verify(canonical)); assertFalse(verify(canonical + byteArrayOf(10)))
        assertEquals(fixture.getString("challenge_sha256"), Protocol.challenge(fixture.getString("pairing_token")).joinToString("") { "%02x".format(it.toInt() and 255) })
    }
}
