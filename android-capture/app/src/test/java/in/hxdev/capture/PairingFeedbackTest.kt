package `in`.hxdev.capture

import `in`.hxdev.capture.core.PairingFeedback
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class PairingFeedbackTest {
    @Test fun patchDiagnosticsAllowOnlyBoundedPolicyValues() {
        val json = JSONObject().put("error", "ATTESTATION_REJECTED").put("code", "PATCH_POLICY")
            .put("details", JSONObject().put("field", "vendor_patch").put("reason", "BELOW_MINIMUM")
                .put("actual", "20260201").put("minimum", "20260401").put("max_age_months", 6)
                .put("server_date", "2026-10-27").put("token", "private-token"))
        val detail = PairingFeedback.safeDetail(json)
        assertTrue(detail.contains("vendor_patch")); assertTrue(detail.contains("20260201"))
        assertFalse(detail.contains("private-token"))
        json.getJSONObject("details").put("actual", "private-token").put("field", "secret")
        assertFalse(PairingFeedback.safeDetail(json).contains("private-token"))
        assertFalse(PairingFeedback.safeDetail(json).contains("secret"))
        assertEquals("", PairingFeedback.safeDetail(JSONObject().put("error", "ATTESTATION_REJECTED").put("code", "private-token")))
    }
    @Test fun knownServerRefusalSurvivesWithoutExposingArbitraryResponseText() {
        assertEquals("DEVICE_SLOT_IN_USE", PairingFeedback.responseCode(422, JSONObject().put("message", "DEVICE_SLOT_IN_USE")))
        val code = PairingFeedback.responseCode(422, JSONObject().put("message", "Private request details or token here"))
        assertEquals("PAIR_HTTP_422", code)
        assertFalse(PairingFeedback.message(code).contains("Private"))
    }
}
