package `in`.hxdev.capture

import android.os.ParcelFileDescriptor
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createAndroidComposeRule
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assume.assumeTrue
import org.junit.Rule
import org.junit.Test

class StagingPairingUiTest {
    @get:Rule val compose = createAndroidComposeRule<MainActivity>()
    @Test fun setupExposesPreparationWithoutSubmittingOrResettingIdentity() {
        assumeTrue(BuildConfig.STAGING_PAIRING)
        val app = compose.activity.application as CaptureApp
        val state = app.repository.staging.view()?.state
        assumeTrue(state == null || state == "uncertain")
        val before = app.repository.prefs.all.toMap()
        val descriptor = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(
            "am start -W -f 0x00020000 -n in.hxdev.capture.debug/in.hxdev.capture.MainActivity")
        ParcelFileDescriptor.AutoCloseInputStream(descriptor).use { it.readBytes() }
        compose.activityRule.scenario.onActivity { it.window.addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) }
        compose.waitUntil(10000) { runCatching { compose.onAllNodesWithText("HX PAY").fetchSemanticsNodes().isNotEmpty() }.getOrDefault(false) }
        compose.onNodeWithText("Setup").performClick()
        compose.onNodeWithText("Check network, permissions and merchant device status, then use recovery in Settings.").assertDoesNotExist()
        compose.onNodeWithText("Staging pairing · prepare, review, submit").performScrollTo().assertIsDisplayed()
        if (state == "uncertain") {
            compose.onNodeWithText("Submission outcome unknown. Ask the operator to check this fingerprint on the server. Retry and replacement are blocked; expiry does not prove rejection.").performScrollTo().assertIsDisplayed()
            compose.onNodeWithText("Prepare key & show fingerprint").assertDoesNotExist()
            compose.onNodeWithText("Pairing needs server recovery. This phone has a retained attempt without a confirmed outcome. Keep app data and ask the operator to resolve the pending key. The last saved error is shown below.").performScrollTo().assertIsDisplayed()
        } else compose.onNodeWithText("Prepare key & show fingerprint").performScrollTo().assertIsNotEnabled()
        compose.onNodeWithText("Submit this saved pairing").assertDoesNotExist()
        compose.onNodeWithText("Prepare a new pairing").assertDoesNotExist()
        org.junit.Assert.assertEquals(before, app.repository.prefs.all)
    }
}
