package `in`.hxdev.capture

import `in`.hxdev.capture.parser.*
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class KotakGatewayFixtureTest {
    private fun resource(name: String) = javaClass.classLoader!!.getResourceAsStream(name)!!.bufferedReader().use { it.readText() }
    @Test fun gatewaySmsFixturesMatchActualRe2ParserAndRejectAlteredMessages() {
        val preset = JSONObject(resource("kotak-sms-preset.json")).getJSONObject("kotak_sms")
            .put("sender_ids", JSONArray().put("XX-KOTAKB"))
            .put("notification_packages", JSONArray().put("test.bank.fixture"))
            .put("notification_signing_sha256", JSONObject().put("test.bank.fixture", JSONArray().put("ab".repeat(32))))
        val rules = Rules.decode(JSONObject().put("version", 1).put("source", "kotak").put("rules", preset))
        val parser = CreditParser(rules)
        val fixtures = JSONArray(resource("kotak-credits.json"))
        for (i in 0 until fixtures.length()) {
            val fixture = fixtures.getJSONObject(i)
            val body = fixture.getString("body")
            assertEquals(fixture.getString("name"), Credit(fixture.getLong("amount_minor"), fixture.getString("reference")), parser.parse("sms", "XX-KOTAKB", body))
            assertTrue(rules.credit.matcher(body).matches())
            for (invalid in listOf("OTP $body", "$body failed", "$body\n$body", body.replace("Received", "Debited"), body.replaceFirst(Regex("Rs\\.[0-9]+\\.[0-9]{2}"), "Rs.0.00"))) {
                assertNull(parser.parse("sms", "XX-KOTAKB", invalid))
            }
            assertNull(parser.parse("sms", "YY-KOTAKB", body))
            assertNull(parser.parse("sms", "XX-KOTAKB", body + "x".repeat(2049)))
        }
    }
}
